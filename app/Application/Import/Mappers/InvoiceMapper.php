<?php

declare(strict_types=1);

namespace App\Application\Import\Mappers;

use App\Application\Import\ImportMapper;
use App\Application\Import\ImportResult;
use App\Application\Import\ImportWriter;
use App\Application\Import\LegacyValues;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A legacy invoice becomes an invoice, with its own number and its own dates.
 *
 * This is the clearest case for the whole phase's central decision: **an import
 * writes rows and calls no use case.** `IssueInvoice` would allocate a number
 * from this installation's sequence, and the customer has the old number in
 * their filing cabinet and their accountant has it in a ledger. An invoice whose
 * number changed in a migration is an invoice nobody can reconcile.
 *
 * So the legacy number, the legacy issue date and the legacy amounts are written
 * as they were. The bill-to party is copied from the customer as it stands today
 * rather than reconstructed from the legacy row, and the report says so — an
 * issued invoice is frozen here (ADR 0023) and an imported one is already
 * issued, so this is the one place where the copy is approximate and it is
 * better to be honest about it than to invent an address from a system that may
 * have stored it differently.
 *
 * **No line items.** A legacy invoice's lines are free text with an amount, and
 * a line here copies a product, an option set and a cycle from an order. Writing
 * the legacy description into a line would produce documents that look right and
 * cannot be recalculated. The totals are what the customer owes and what the
 * accounts need; the lines are on the original document.
 *
 * **`paid_minor` comes from the legacy row**, not from the transactions. The
 * ledger is the truth for invoices raised *here* (ADR 0024); for an imported one
 * the legacy system is the truth, and rebuilding the cache from transactions
 * that may not all have come across would mark settled invoices unpaid.
 */
final readonly class InvoiceMapper implements ImportMapper
{
    public function __construct(private OrganizationContext $organizations) {}

    public function domain(): ImportDomain
    {
        return ImportDomain::Invoices;
    }

    public function map(ImportRecord $record, ImportWriter $writer): ImportResult
    {
        $clientId = $record->text('userid');
        $customerId = $writer->mappedId(ImportDomain::Customers, $clientId);

        if ($customerId === null) {
            return ImportResult::failed("Its client [{$clientId}] was not imported.");
        }

        $customer = Customer::query()
            ->withoutGlobalScope('organization')
            ->with(Customer::displayNameWith())
            ->find($customerId);

        if ($customer === null) {
            return ImportResult::failed('Its customer no longer exists.');
        }

        $currency = LegacyValues::currency($record->text('currency'), $customer->currency_code);

        if ($currency === null) {
            return ImportResult::failed('Its currency is not one this platform knows.');
        }

        $issued = LegacyValues::date($record->get('date'));

        if ($issued === null) {
            // An invoice with no date cannot be filed, aged or reported on.
            return ImportResult::failed('It has no issue date.');
        }

        $subtotal = LegacyValues::money($record->decimal('subtotal'), $currency);
        $tax = LegacyValues::money($record->decimal('tax'), $currency);
        $total = LegacyValues::money($record->decimal('total'), $currency);
        $credit = LegacyValues::money($record->decimal('credit'), $currency);

        return $writer->create($this->domain(), $record->externalId, fn (): Model => $this->organizations->runAs(
            $customer->organization_id,
            fn (): Invoice => Invoice::query()->create([
                'organization_id' => $customer->organization_id,
                // The legacy number, kept. This is the whole reason no use case
                // is called: the customer has this number on paper.
                'number' => $record->text('invoicenum') ?: 'IMP-'.$record->externalId,
                'customer_id' => $customer->id,
                'status' => $this->status($record->text('status')),
                'currency_code' => $currency,
                // Copied from the customer as it stands today, which is
                // approximate and is stated in the docblock rather than hidden.
                'bill_to_name' => $customer->displayName(),
                'bill_to_company' => $customer->company_name,
                'bill_to_country' => null,
                'bill_to_email' => null,
                'subtotal_minor' => $subtotal->minorUnits,
                'discount_minor' => 0,
                'tax_minor' => $tax->minorUnits,
                'total_minor' => $total->minorUnits,
                // The legacy system's word on what was paid. Rebuilding it from
                // transactions that may not all have come across would mark
                // settled invoices unpaid.
                'paid_minor' => $credit->minorUnits,
                'issued_on' => $issued->toDateString(),
                'due_on' => LegacyValues::date($record->get('duedate'))?->toDateString()
                    ?? $issued->toDateString(),
                'paid_at' => LegacyValues::date($record->get('datepaid')),
                'is_proforma' => false,
                'notes' => $record->text('notes') ?: null,
            ]),
        ));
    }

    /**
     * A legacy invoice status.
     *
     * Never `draft`. A draft is the only editable state here and an imported
     * invoice is a document that already exists — importing one as a draft would
     * invite somebody to edit a document their customer already has.
     */
    private function status(string $legacy): string
    {
        return match (strtolower($legacy)) {
            'paid' => InvoiceStatus::Paid->value,
            'cancelled' => InvoiceStatus::Cancelled->value,
            'refunded' => InvoiceStatus::Refunded->value,
            'collections', 'overdue' => InvoiceStatus::Overdue->value,
            'payment pending', 'unpaid' => InvoiceStatus::Unpaid->value,
            default => InvoiceStatus::Unpaid->value,
        };
    }
}
