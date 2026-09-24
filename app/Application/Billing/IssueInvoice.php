<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\InvoiceNotIssuable;
use App\Application\Shared\AllocateNumber;
use App\Domain\Billing\Events\InvoiceIssued;
use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Support\Audit\Facades\Audit;
use App\Support\Correlation\CorrelationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Turns a draft into a document.
 *
 * This is the moment the invoice stops being a working copy: it takes a
 * number from the sequence, copies the bill-to party onto itself, and from
 * here on nothing about it changes. Correcting it afterwards means a credit
 * note.
 *
 * The number is allocated inside the same transaction that issues the
 * invoice, so a rollback cannot leave a gap in the sequence.
 *
 * The seller's document note is copied onto the document here, which is the only
 * correct moment for it: the wording a country obliges an invoice to carry is
 * part of what the customer received, so changing it next year must not change
 * what last year's invoices say. An invoice that already carries its own terms
 * keeps them — an operator who typed something onto one document meant that
 * document.
 */
final readonly class IssueInvoice
{
    public function __construct(
        private AllocateNumber $numbers,
        private TransitionInvoice $transitions,
        private BillingSettings $settings,
    ) {}

    /**
     * @param  bool  $notify  false when the desk will hand the invoice over itself
     */
    public function handle(Invoice $invoice, ?Model $actor = null, bool $notify = true): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            // Issuing twice is almost always a double-submitted form; the
            // document that exists is the right answer.
            return $invoice;
        }

        if ($invoice->items()->doesntExist()) {
            throw InvoiceNotIssuable::withoutLines();
        }

        $customer = $invoice->customer;

        // Read before the transaction: it is a query against another
        // organization's row and has nothing to do with writing this document.
        $terms = $invoice->terms ?? $this->settings
            ->forOrganization($invoice->organization_id)
            ->document_note;

        DB::transaction(function () use ($invoice, $customer, $terms): void {
            $invoice->forceFill([
                'number' => $this->numbers->handle(
                    $invoice->organization_id,
                    $invoice->is_proforma ? 'proforma' : 'invoice',
                    (string) config(
                        $invoice->is_proforma
                            ? 'platform.billing.numbering.proforma_prefix'
                            : 'platform.billing.numbering.invoice_prefix',
                        'INV-',
                    ),
                    (int) config('platform.billing.numbering.padding', 6),
                ),
                'issued_on' => CarbonImmutable::now()->toDateString(),
                'terms' => $terms,
                ...($customer === null ? [] : CreateInvoiceFromOrder::billTo($customer)),
            ])->save();
        });

        $issued = $this->transitions->handle($invoice, InvoiceStatus::Unpaid, $actor);

        // Announced after the transaction, like every event here: a
        // listener must never see a row that is not committed yet.
        // The document exists either way. What `notify` decides is
        // whether anybody is told about it now — an operator reading an
        // invoice number down the phone does not want the customer's copy
        // arriving mid-sentence.
        if ($notify) {
            event(new InvoiceIssued(
                $issued->id,
                $issued->organization_id,
                app(CorrelationContext::class)->id(),
            ));
        }

        Audit::action('billing.invoice.issued')
            ->by($actor)
            ->on($issued)
            ->forOrganization($issued->organization_id)
            ->withMetadata([
                'number' => $issued->number,
                'total' => $issued->total->toDecimalString(),
                'currency' => $issued->currency_code,
                'due_on' => $issued->due_on?->toDateString(),
            ])
            ->write();

        return $issued;
    }
}
