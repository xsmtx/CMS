<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\PaymentRefused;
use App\Domain\Billing\TransactionKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Spend a customer's credit on an invoice.
 *
 * Two rows, not one: the credit comes off the balance and goes onto the
 * invoice. Netting them into a single row would make "where did my credit
 * go" unanswerable.
 */
final readonly class ApplyCredit
{
    public function __construct(
        private Ledger $ledger,
        private RecordPayment $payments,
    ) {}

    public function handle(Invoice $invoice, Money $amount, ?Model $actor = null): Invoice
    {
        $customer = $invoice->customer;

        if ($customer === null) {
            throw PaymentRefused::alreadyPaid();
        }

        if ($amount->currency->code !== $invoice->currency_code) {
            throw PaymentRefused::currencyMismatch($invoice->currency_code, $amount->currency->code);
        }

        $balance = $this->ledger->creditBalance($customer, $invoice->currency_code);

        if (! $amount->isPositive() || $amount->isGreaterThan($balance)) {
            throw PaymentRefused::exceedsCredit($amount, $balance);
        }

        $owed = $invoice->balance();

        if (! $owed->isPositive()) {
            throw PaymentRefused::alreadyPaid();
        }

        // Never more than is owed: credit left over stays credit.
        $applied = $amount->isGreaterThan($owed) ? $owed : $amount;

        DB::transaction(function () use ($customer, $invoice, $applied, $actor): void {
            $this->ledger->record(
                customer: $customer,
                kind: TransactionKind::CreditApplied,
                amount: $applied,
                invoice: $invoice,
                description: 'Credit applied to '.$invoice->number,
                recordedBy: $actor?->getAttribute('email'),
            );
        });

        $settled = $this->payments->settle($invoice, $actor);

        Audit::action('billing.credit.applied')
            ->by($actor)
            ->on($invoice)
            ->forOrganization($invoice->organization_id)
            ->withMetadata([
                'amount' => $applied->toDecimalString(),
                'currency' => $applied->currency->code,
                'invoice' => $invoice->number,
            ])
            ->write();

        return $settled;
    }
}
