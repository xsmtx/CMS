<?php

declare(strict_types=1);

namespace App\Application\Import\Mappers;

use App\Application\Import\ImportMapper;
use App\Application\Import\ImportResult;
use App\Application\Import\ImportWriter;
use App\Application\Import\LegacyValues;
use App\Domain\Billing\TransactionKind;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A legacy payment becomes a ledger row.
 *
 * **`RecordPayment` is not called, and that is the sharpest edge in this phase.**
 * It is the one path that settles an invoice, and calling it here would be
 * right in every way except two: it would recompute a paid amount the legacy
 * system has already settled, and it would raise `PaymentReceived` — which
 * advances renewal dates and sends a receipt. Importing two years of payments
 * through it would email every customer a receipt for a payment they made
 * eighteen months ago.
 *
 * So the row is written directly, the invoice's `paid_minor` keeps what the
 * legacy invoice said, and the `credit_balance_minor` running total is **not**
 * reconstructed: a legacy system's credit history is its own arithmetic, and a
 * partially imported one would produce a balance that is wrong in a way nobody
 * could unpick. An imported transaction is history a report can read, and the
 * customer's credit balance starts from whatever their imported invoices imply.
 *
 * That is a real limitation and it is written down rather than papered over: the
 * result document says an imported installation's account credit is not carried
 * across, because doing it halfway is worse than not doing it.
 */
final readonly class TransactionMapper implements ImportMapper
{
    public function __construct(private OrganizationContext $organizations) {}

    public function domain(): ImportDomain
    {
        return ImportDomain::Transactions;
    }

    public function map(ImportRecord $record, ImportWriter $writer): ImportResult
    {
        $clientId = $record->text('userid');
        $customerId = $writer->mappedId(ImportDomain::Customers, $clientId);

        if ($customerId === null) {
            return ImportResult::failed("Its client [{$clientId}] was not imported.");
        }

        $customer = Customer::query()->withoutGlobalScope('organization')->find($customerId);

        if ($customer === null) {
            return ImportResult::failed('Its customer no longer exists.');
        }

        $currency = LegacyValues::currency($record->text('currency'), $customer->currency_code);

        if ($currency === null) {
            return ImportResult::failed('Its currency is not one this platform knows.');
        }

        $occurred = LegacyValues::date($record->get('date'));

        if ($occurred === null) {
            // A ledger is ordered by when money moved. A row with no date has
            // no place in it.
            return ImportResult::failed('It has no date.');
        }

        $in = LegacyValues::money($record->decimal('amountin'), $currency);
        $out = LegacyValues::money($record->decimal('amountout'), $currency);

        // A legacy row can hold both, or neither. Neither is not a movement.
        if ($in->isZero() && $out->isZero()) {
            return ImportResult::skipped('It moved no money.');
        }

        $isRefund = $in->isZero();
        $amount = $isRefund ? $out : $in;

        // An invoice is optional: a legacy system records credit top-ups and
        // manual adjustments against no invoice at all.
        $invoiceId = $writer->mappedId(ImportDomain::Invoices, $record->text('invoiceid'));

        return $writer->create($this->domain(), $record->externalId, fn (): Model => $this->organizations->runAs(
            $customer->organization_id,
            fn (): Transaction => Transaction::query()->create([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'invoice_id' => $invoiceId,
                'kind' => $isRefund ? TransactionKind::Refund->value : TransactionKind::Payment->value,
                'currency_code' => $currency,
                // Always positive; the kind decides direction (ADR 0024).
                'amount_minor' => $amount->minorUnits,
                // Not reconstructed. See the class docblock: a half-imported
                // credit history is a balance nobody can unpick.
                'credit_balance_minor' => 0,
                'description' => $this->describe($record),
                'recorded_by' => 'Imported',
                'occurred_at' => $occurred,
            ]),
        ));
    }

    private function describe(ImportRecord $record): string
    {
        $gateway = $record->text('gateway');
        $reference = $record->text('transid');

        $parts = array_filter([
            $gateway === '' ? null : $gateway,
            $reference === '' ? null : 'ref '.$reference,
            $record->text('description') ?: null,
        ]);

        return $parts === [] ? 'Imported payment' : implode(' · ', $parts);
    }
}
