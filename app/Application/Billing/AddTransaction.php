<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\PaymentRefused;
use App\Domain\Billing\TransactionKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * An operator writing down money that moved outside the platform.
 *
 * A wire transfer that landed in the bank, a gateway payout reconciled by
 * hand, a chargeback that came back a fortnight later. The platform did
 * not see any of it happen; somebody with a statement in front of them
 * says it did.
 *
 * **This does not write the invoice.** When invoices are named, the money
 * goes through `RecordPayment` — the one path that settles an invoice
 * (ADR 0024) — so an operator's transfer moves the invoice, the order and
 * the ledger exactly as a webhook would. The alternative, a ledger row
 * written here plus `paid_minor` bumped by hand, is the bug that rule
 * exists to prevent.
 *
 * Money going out is the same shape in reverse: a `Refund` row, then
 * `RecordPayment::settle()` to rebuild the invoice from the ledger. The
 * arithmetic lives in one place whichever direction the money went.
 *
 * Applied across several invoices in the order they were given, each
 * taking what it is owed and no more. Anything left over is credit, which
 * is what an overpayment has always been here.
 */
final readonly class AddTransaction
{
    public function __construct(
        private Ledger $ledger,
        private RecordPayment $payments,
        private AddCredit $credits,
    ) {}

    /**
     * @return list<Transaction> every row this wrote, in the order it wrote them
     */
    public function handle(Customer $customer, AddTransactionRequest $request, ?Model $actor = null): array
    {
        $this->assertOneDirection($request);

        $invoices = $this->invoicesFor($customer, $request);
        $written = $request->isIncoming()
            ? $this->receive($customer, $request, $invoices, $actor)
            : $this->pay($customer, $request, $invoices, $actor);

        Audit::action('billing.transaction.added')
            ->by($actor)
            ->on($customer)
            ->forOrganization($customer->organization_id)
            ->because($request->description ?? '')
            ->withMetadata([
                'direction' => $request->isIncoming() ? 'in' : 'out',
                'amount' => $request->amount()->toDecimalString(),
                'currency' => $request->amount()->currency->code,
                'fees' => $request->fees?->toDecimalString(),
                'gateway' => $request->gateway,
                'reference' => $request->reference,
                'invoices' => array_map(static fn (Invoice $invoice): string => $invoice->number, $invoices),
                'to_credit' => $request->toCreditBalance,
            ])
            ->write();

        return $written;
    }

    /**
     * Money in: settle what it was sent for, bank the rest.
     *
     * @param  list<Invoice>  $invoices
     * @return list<Transaction>
     */
    private function receive(
        Customer $customer,
        AddTransactionRequest $request,
        array $invoices,
        ?Model $actor,
    ): array {
        $remaining = $request->amountIn;
        $written = [];

        // The fee is charged once, against the first row this produces.
        // Spreading it across the invoices would invent a split nobody
        // agreed to, and charging it on each would multiply it.
        $fees = $request->fees;

        foreach ($invoices as $invoice) {
            if (! $remaining->isPositive()) {
                break;
            }

            $balance = $invoice->balance();
            $applied = $balance->isPositive() && $remaining->isGreaterThan($balance) ? $balance : $remaining;

            if (! $applied->isPositive()) {
                continue;
            }

            $payment = $this->payments->handle($invoice, new RecordPaymentRequest(
                amount: $applied,
                gateway: $request->gateway,
                reference: $request->reference,
                receivedAt: $request->occurredAt,
                recordedBy: $request->recordedBy,
                note: $request->description,
                fees: $fees,
            ), $actor);

            $fees = null;
            $remaining = $remaining->minus($applied);
            $written = [...$written, ...$payment->transactions()->get()->all()];
        }

        if (! $remaining->isPositive()) {
            return $written;
        }

        // What is left has to go somewhere a customer can spend it. An
        // operator who did not ask for that is asked to say where it
        // belongs rather than having it decided for them.
        if (! $request->toCreditBalance && $invoices === []) {
            throw PaymentRefused::nowhereToPutIt();
        }

        return [...$written, $this->credits->handle(
            $customer,
            $remaining,
            $request->description ?? __('billing.transactions.recorded_by_hand'),
            $actor,
        )];
    }

    /**
     * Money out: a refund against what it came from, or credit taken back.
     *
     * @param  list<Invoice>  $invoices
     * @return list<Transaction>
     */
    private function pay(
        Customer $customer,
        AddTransactionRequest $request,
        array $invoices,
        ?Model $actor,
    ): array {
        $remaining = $request->amountOut;
        $written = [];
        $fees = $request->fees;

        foreach ($invoices as $invoice) {
            if (! $remaining->isPositive()) {
                break;
            }

            // Never more than the invoice actually took in: a refund
            // larger than the payment is a different conversation, and
            // `RefundPayment` is where it happens.
            $paid = $invoice->paid;
            $applied = $remaining->isGreaterThan($paid) ? $paid : $remaining;

            if (! $applied->isPositive()) {
                continue;
            }

            $written[] = $this->ledger->record(
                customer: $customer,
                kind: TransactionKind::Refund,
                amount: $applied,
                invoice: $invoice,
                description: $request->description,
                recordedBy: $request->recordedBy,
                fees: $fees,
                gateway: $request->gateway,
                reference: $request->reference,
                occurredAt: $request->occurredAt,
            );

            $fees = null;
            $remaining = $remaining->minus($applied);

            // The one place that recomputes an invoice from the rows. A
            // refund and a payment change the same numbers and must use
            // the same arithmetic.
            $this->payments->settle($invoice, $actor);
        }

        if (! $remaining->isPositive()) {
            return $written;
        }

        $written[] = $this->ledger->record(
            customer: $customer,
            kind: $request->toCreditBalance ? TransactionKind::CreditApplied : TransactionKind::Adjustment,
            amount: $remaining,
            description: $request->description,
            recordedBy: $request->recordedBy,
            fees: $fees,
            gateway: $request->gateway,
            reference: $request->reference,
            occurredAt: $request->occurredAt,
        );

        return $written;
    }

    /**
     * The invoices named, narrowed to this customer.
     *
     * Looked up through the customer's own relation rather than by id
     * alone: a number typed into a box is not proof that the invoice
     * belongs to the client on the form.
     *
     * @return list<Invoice>
     */
    private function invoicesFor(Customer $customer, AddTransactionRequest $request): array
    {
        if ($request->invoiceIds === []) {
            return [];
        }

        $invoices = Invoice::query()
            // `RecordPayment::attach()` reads `$invoice->customer`, and
            // strict mode only reports a lazy load once a query returns
            // more than one row — so splitting a transfer across two
            // invoices is exactly the case a single-invoice test misses.
            // `markOrderPaid()` reads `$invoice->order` for the same
            // reason.
            ->with([...Customer::displayNameWith('customer'), 'order'])
            ->where('customer_id', $customer->id)
            ->where(fn ($query) => $query
                ->whereIn('id', $request->invoiceIds)
                ->orWhereIn('number', $request->invoiceIds))
            ->get();

        foreach ($invoices as $invoice) {
            if ($invoice->currency_code !== $request->amount()->currency->code) {
                throw PaymentRefused::currencyMismatch(
                    $invoice->currency_code,
                    $request->amount()->currency->code,
                );
            }
        }

        // Kept in the order they were typed: an operator splitting a
        // transfer across three invoices means the order they listed.
        $byKey = $invoices->keyBy('id')->all() + $invoices->keyBy('number')->all();

        return array_values(array_filter(array_map(
            static fn (string $key): ?Invoice => $byKey[$key] ?? null,
            $request->invoiceIds,
        )));
    }

    private function assertOneDirection(AddTransactionRequest $request): void
    {
        $in = $request->amountIn;
        $out = $request->amountOut;

        if ($in->isPositive() === $out->isPositive()) {
            throw PaymentRefused::ambiguousDirection();
        }

        if ($in->currency->code !== $out->currency->code) {
            throw PaymentRefused::currencyMismatch($in->currency->code, $out->currency->code);
        }

        $this->assertFeesFit($request->fees, $request->amount());
    }

    private function assertFeesFit(?Money $fees, Money $amount): void
    {
        if ($fees instanceof Money && $fees->currency->code !== $amount->currency->code) {
            throw PaymentRefused::currencyMismatch($amount->currency->code, $fees->currency->code);
        }
    }
}
