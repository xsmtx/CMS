<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\InvoiceNotIssuable;
use App\Application\Shared\AllocateNumber;
use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Support\Audit\Facades\Audit;
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
 */
final readonly class IssueInvoice
{
    public function __construct(
        private AllocateNumber $numbers,
        private TransitionInvoice $transitions,
    ) {}

    public function handle(Invoice $invoice, ?Model $actor = null): Invoice
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

        DB::transaction(function () use ($invoice, $customer): void {
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
                ...($customer === null ? [] : CreateInvoiceFromOrder::billTo($customer)),
            ])->save();
        });

        $issued = $this->transitions->handle($invoice, InvoiceStatus::Unpaid, $actor);

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
