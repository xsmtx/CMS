<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\PaymentRefused;
use App\Application\Ordering\TransitionOrder;
use App\Domain\Billing\Events\PaymentReceived;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\TransactionKind;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Payment;
use App\Support\Audit\Facades\Audit;
use App\Support\Correlation\CorrelationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Money arrived.
 *
 * The single path by which an invoice becomes paid, whether the money came
 * from a gateway webhook or an operator saying a transfer landed. Anything
 * that writes `paid_minor` directly is a bug: this recalculates it from the
 * ledger, transitions the invoice, and — when the invoice came from an
 * order — moves the order too, so an invoice cannot be settled without the
 * order noticing.
 *
 * Overpayment is not an error. The excess becomes account credit, which is
 * a ledger row like any other.
 */
final readonly class RecordPayment
{
    public function __construct(
        private Ledger $ledger,
        private TransitionInvoice $transitions,
        private TransitionOrder $orders,
    ) {}

    /**
     * Record money an operator says arrived.
     */
    public function handle(Invoice $invoice, RecordPaymentRequest $request, ?Model $actor = null): Payment
    {
        $this->assertAcceptable($invoice, $request->amount);

        $payment = Payment::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'gateway' => $request->gateway,
            'status' => PaymentStatus::Completed->value,
            'currency_code' => $request->amount->currency->code,
            'amount_minor' => $request->amount->minorUnits,
            'fees_minor' => $request->fees instanceof Money ? abs($request->fees->minorUnits) : 0,
            'reference' => $request->reference ?? 'man_'.Str::lower(Str::random(20)),
            'idempotency_key' => $request->idempotencyKey ?? Str::lower(Str::random(32)),
            'received_at' => $request->receivedAt ?? CarbonImmutable::now(),
            'recorded_by' => $request->recordedBy,
            'note' => $request->note,
        ]);

        $this->attach($invoice, $payment, $actor);

        Audit::action('billing.payment.recorded')
            ->by($actor)
            ->on($payment)
            ->forOrganization($payment->organization_id)
            ->withMetadata([
                'invoice' => $invoice->number,
                'amount' => $request->amount->toDecimalString(),
                'currency' => $request->amount->currency->code,
                'gateway' => $request->gateway,
            ])
            ->write();

        return $payment;
    }

    /**
     * Write the ledger for a payment that already exists, and settle.
     *
     * Used when a gateway confirms a payment that was created as pending
     * before the customer was sent away. Idempotent: a webhook delivered
     * again under a second event id finds the rows already written and
     * leaves them alone.
     */
    public function attach(Invoice $invoice, Payment $payment, ?Model $actor = null): Invoice
    {
        $customer = $invoice->customer;

        if ($customer === null || $payment->transactions()->exists()) {
            return $this->settle($invoice, $actor);
        }

        DB::transaction(function () use ($invoice, $payment, $customer): void {
            $balance = $invoice->balance();

            // The ledger's payment row records what was applied to this
            // invoice; anything beyond it is credit, on its own row. That
            // keeps "what has this invoice been paid" a straight sum.
            $applied = $payment->amount->isGreaterThan($balance) && $balance->isPositive()
                ? $balance
                : $payment->amount;

            if ($applied->isPositive()) {
                $this->ledger->record(
                    customer: $customer,
                    kind: TransactionKind::Payment,
                    amount: $applied,
                    invoice: $invoice,
                    payment: $payment,
                    description: $payment->note,
                    recordedBy: $payment->recorded_by,
                    fees: $payment->fees,
                    // Copied onto the row rather than joined: the ledger is
                    // read as a statement, and a row that needs a join to
                    // say how the money moved reads wrong in a list.
                    gateway: $payment->gateway,
                    reference: $payment->reference,
                    occurredAt: $payment->received_at,
                );
            }

            $excess = $payment->amount->minus($applied);

            if ($excess->isPositive()) {
                $this->ledger->record(
                    customer: $customer,
                    kind: TransactionKind::CreditAdded,
                    amount: $excess,
                    invoice: $invoice,
                    payment: $payment,
                    description: 'Overpayment on '.$invoice->number,
                    recordedBy: $payment->recorded_by,
                    gateway: $payment->gateway,
                    reference: $payment->reference,
                    occurredAt: $payment->received_at,
                );
            }
        });

        $settled = $this->settle($invoice, $actor);

        event(new PaymentReceived(
            $payment->id,
            $invoice->id,
            $invoice->organization_id,
            app(CorrelationContext::class)->id(),
        ));

        return $settled;
    }

    /**
     * Recalculate an invoice from the ledger and move it if it settled.
     *
     * Public because a refund and a credit note change the same numbers and
     * must use the same arithmetic.
     */
    public function settle(Invoice $invoice, ?Model $actor = null): Invoice
    {
        $invoice->refresh();
        $paid = $this->ledger->paidTowards($invoice);

        $invoice->forceFill(['paid_minor' => $paid->minorUnits])->save();

        $covered = $paid->isGreaterThan($invoice->total) || $paid->equals($invoice->total);

        $target = match (true) {
            // Money went back out of an invoice that was settled. An
            // issued invoice is frozen (ADR 0023), so it does not walk
            // backwards to unpaid — `Refunded` is the one move out of
            // `Paid`, and it is the truthful one. Without this the
            // document says Paid while the ledger says the money left,
            // which is the disagreement this screen exists to find.
            $invoice->status === InvoiceStatus::Paid && ! $covered => InvoiceStatus::Refunded,
            ! $paid->isPositive() && $invoice->status !== InvoiceStatus::Draft => $invoice->isPastDue()
                ? InvoiceStatus::Overdue
                : InvoiceStatus::Unpaid,
            $covered => InvoiceStatus::Paid,
            default => InvoiceStatus::PartiallyPaid,
        };

        if ($invoice->status !== $target && $invoice->status->canTransitionTo($target)) {
            $invoice = $this->transitions->handle($invoice, $target, $actor);
        }

        if ($target === InvoiceStatus::Paid) {
            $this->markOrderPaid($invoice, $actor);
        }

        return $invoice;
    }

    private function assertAcceptable(Invoice $invoice, Money $amount): void
    {
        if ($amount->currency->code !== $invoice->currency_code) {
            throw PaymentRefused::currencyMismatch($invoice->currency_code, $amount->currency->code);
        }

        if (! $amount->isPositive()) {
            throw PaymentRefused::exceedsBalance($amount, $invoice->balance());
        }

        // Nothing is owed on a document that has not been issued, and
        // nothing more is owed on one already settled or cancelled.
        if ($invoice->status === InvoiceStatus::Draft
            || $invoice->status->isSettled()
            || $invoice->status === InvoiceStatus::Cancelled) {
            throw PaymentRefused::alreadyPaid();
        }
    }

    /**
     * An order whose invoice is paid is paid.
     *
     * The transition is attempted rather than assumed: an order that has
     * already moved on — provisioning, active — must not be dragged
     * backwards by a late payment row.
     */
    private function markOrderPaid(Invoice $invoice, ?Model $actor): void
    {
        $order = $invoice->order;

        if ($order === null || ! $order->status->canTransitionTo(OrderStatus::Paid)) {
            return;
        }

        $this->orders->handle($order, OrderStatus::Paid, $actor, 'Invoice '.$invoice->number.' paid');
    }
}
