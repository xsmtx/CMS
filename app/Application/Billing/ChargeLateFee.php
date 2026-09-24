<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\BillingSetting;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The charge for paying late, as its own invoice.
 *
 * **A new document, not a line** ([ADR 0046](../../../docs/adr/0046-a-late-fee-is-a-new-invoice.md)).
 * The invoice the fee is about has been issued, and an issued invoice is frozen
 * (ADR 0023): every amount on it was fixed the moment the customer received it,
 * and a platform that quietly grew a line on a document somebody has already
 * filed is a platform whose documents mean nothing. The other option — waiting
 * and putting the fee on the next renewal — arrives weeks after the debt it
 * exists to discourage, and lands on a customer who may have no next renewal.
 *
 * The fee is a **percentage of what is still outstanding**, not of the total. An
 * invoice half paid is half a debt, and charging interest on money that has
 * already arrived is the kind of error a customer notices and never forgets.
 *
 * It is issued immediately rather than left as a draft, for the same reason a
 * renewal invoice is: issuing is what tells the customer, and a fee nobody was
 * told about cannot change anybody's behaviour.
 *
 * The new invoice carries no tax. Whether interest is a taxable supply is a
 * question with a different answer in nearly every jurisdiction, and guessing at
 * one is precisely what ADR 0045 exists to stop — an operator who must charge
 * tax on a fee states a rule for it, and this returns to the question when
 * `TaxAppliesTo` grows a member for it.
 */
final readonly class ChargeLateFee
{
    public function __construct(
        private BillingSettings $settings,
        private IssueInvoice $issuer,
    ) {}

    /**
     * Raise the fee for one overdue invoice, or nothing at all.
     *
     * Returns null rather than throwing when there is nothing to charge — no
     * rate, nothing outstanding, or a fee that rounds to zero on a small
     * balance. A dunning step that found nothing to do is not a failure, and
     * treating it as one would leave the step unrecorded and retried nightly
     * forever.
     */
    public function handle(Invoice $invoice, ?Model $actor = null): ?Invoice
    {
        $settings = $this->settings->forOrganization($invoice->organization_id);

        if (! $settings->chargesLateFee()) {
            return null;
        }

        $outstanding = $invoice->balance();

        if ($outstanding->minorUnits <= 0) {
            return null;
        }

        $fee = $settings->lateFeeOn($outstanding);

        // A percentage of a small balance can round to nothing. An invoice for
        // zero is a document that says nothing and cannot be paid.
        if ($fee->minorUnits <= 0) {
            return null;
        }

        $description = $this->descriptionFor($settings, $invoice);

        $charge = DB::transaction(function () use ($invoice, $fee, $description): Invoice {
            $charge = Invoice::query()->create([
                'organization_id' => $invoice->organization_id,
                'number' => 'DRAFT-'.Str::upper(Str::random(10)),
                'customer_id' => $invoice->customer_id,
                'status' => InvoiceStatus::Draft->value,
                'currency_code' => $invoice->currency_code,
                'subtotal_minor' => $fee->minorUnits,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => $fee->minorUnits,
                // How the dunning sweep knows not to chase this one. Without
                // it the fee is overdue tomorrow and earns a fee of its own,
                // nightly, and a suspend step takes a server down over three
                // euros of interest.
                'is_late_fee' => true,
                // Immediately. A charge for being late that is itself given
                // thirty days to pay is an argument, not a deterrent.
                'due_on' => CarbonImmutable::now()->toDateString(),
            ]);

            InvoiceItem::query()->create([
                'organization_id' => $charge->organization_id,
                'invoice_id' => $charge->id,
                // The invoice being charged for, so the fee can be traced back
                // to the debt rather than read as an unexplained amount.
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->id,
                'description' => $description,
                'quantity' => 1,
                'currency_code' => $charge->currency_code,
                'unit_amount_minor' => $fee->minorUnits,
                'line_amount_minor' => $fee->minorUnits,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'position' => 0,
            ]);

            return $charge;
        });

        // Outside the transaction: issuing raises an event, and nothing is
        // dispatched from inside one (ADR 0027).
        $charge = $this->issuer->handle($charge, $actor);

        Audit::action('billing.late_fee.charged')
            ->by($actor)
            ->on($charge)
            ->forOrganization($charge->organization_id)
            ->withMetadata([
                'overdue_invoice' => $invoice->number,
                'outstanding' => $outstanding->toDecimalString(),
                'rate' => $settings->lateFeePercentage(),
                'fee' => $fee->toDecimalString(),
                'currency' => $charge->currency_code,
            ])
            ->write();

        return $charge;
    }

    /**
     * What the line says.
     *
     * The operator's own wording when they gave one, because their customers
     * will read it and some of them will dispute it. The fallback names the
     * invoice it is about: "Late payment fee" alone, on a separate document
     * arriving weeks later, is an amount nobody can connect to anything.
     */
    private function descriptionFor(BillingSetting $settings, Invoice $invoice): string
    {
        $label = $settings->late_fee_label;

        if ($label !== null && trim($label) !== '') {
            return trim($label).' — '.$invoice->number;
        }

        return __('billing.late_fee.description', ['invoice' => $invoice->number]);
    }
}
