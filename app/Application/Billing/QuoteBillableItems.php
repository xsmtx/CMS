<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\BillableItem;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use Carbon\CarbonImmutable;

/**
 * Puts one-off charges onto the next invoice (`whmcs-parity-plan.md` §2.2).
 *
 * **A line quotes a row; nothing is recomputed.** The shape `QuoteUsage`
 * already has, and for the same reason: an invoice is frozen at issue
 * (ADR 0023), so the figure on it must come from a row that cannot change —
 * and the row is stamped with the line that quoted it, which is what makes
 * this safe inside a run that may be retried.
 *
 * **Only uncharged and due.** `charge_on` is "not before", so an item dated
 * next month waits for next month's invoice rather than riding along on
 * tonight's. The scope is on the model because there are two callers and the
 * null case is the one somebody writing it by hand gets backwards.
 *
 * **A currency mismatch is skipped rather than converted.** Money is never
 * converted in this product, so a charge in euros has no business on a
 * sterling invoice — it waits for one in its own currency, exactly as a usage
 * snapshot does.
 *
 * **Zero is a line, not nothing.** A charge an operator recorded as free —
 * an hour written off, a part supplied at no cost — is the one they most want
 * the customer to see on the document.
 */
final readonly class QuoteBillableItems
{
    /**
     * @return array{lines: int, total: Money}
     */
    public function handle(Invoice $invoice, string $customerId, int $position, ?CarbonImmutable $now = null): array
    {
        $total = Money::zero($invoice->currency_code);
        $lines = 0;

        $items = BillableItem::query()
            ->withoutGlobalScope('organization')
            ->where('customer_id', $customerId)
            ->where('currency_code', $invoice->currency_code)
            ->chargeable($now)
            ->oldest()
            ->get();

        foreach ($items as $item) {
            $amount = $item->total();
            $total = $total->plus($amount);

            $line = InvoiceItem::query()->create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                /*
                 * The item, not the service it is about: this line is one
                 * recorded charge and the way back to it is the row itself.
                 * A billable item that names a service still says so in its
                 * description, where a customer reads it.
                 */
                'subject_type' => BillableItem::class,
                'subject_id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'currency_code' => $invoice->currency_code,
                'unit_amount_minor' => $item->unit_amount_minor,
                'line_amount_minor' => $amount->minorUnits,
                'discount_minor' => 0,
                /*
                 * No tax, and that is the renewal sweep's decision rather
                 * than an omission: a renewal invoice in this product carries
                 * none, and one taxed line among untaxed ones would be a
                 * document nobody can reconcile.
                 */
                'tax_minor' => 0,
                /*
                 * No period. A one-off charge is not a term being bought, and
                 * `AdvanceRenewalDates` reads the period to decide what a
                 * payment extends — the rule the prorated upgrade line also
                 * lives under.
                 */
                'position' => $position + $lines,
            ]);

            // Stamped before anything else can quote it. An item with an
            // invoice item has been charged for, once and for ever.
            $item->forceFill([
                'invoice_item_id' => $line->id,
                'charged_at' => CarbonImmutable::now(),
            ])->save();

            $lines++;
        }

        return ['lines' => $lines, 'total' => $total];
    }

    /**
     * Customers with something waiting and in which currency.
     *
     * Read by the renewal sweep so that a customer whose only due charge is a
     * one-off still gets an invoice. Without it an item could wait months for
     * a renewal that is not coming — which is the shape of a charge somebody
     * recorded and nobody was ever billed for.
     *
     * @return list<array{customer_id: string, currency_code: string}>
     */
    public function waiting(?CarbonImmutable $now = null): array
    {
        return array_values(BillableItem::query()
            ->withoutGlobalScope('organization')
            ->chargeable($now)
            ->select(['customer_id', 'currency_code'])
            ->distinct()
            ->get()
            ->map(static fn (BillableItem $item): array => [
                'customer_id' => $item->customer_id,
                'currency_code' => $item->currency_code,
            ])
            ->all());
    }
}
