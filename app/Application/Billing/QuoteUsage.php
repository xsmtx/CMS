<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\UsageMeterRecord;
use App\Infrastructure\Billing\Models\UsageSnapshot;
use App\Infrastructure\Provisioning\Models\Service;

/**
 * Puts what a service used onto the invoice that renews it (§25).
 *
 * **A line quotes a snapshot; nothing is recomputed.** §25 asks for immutable
 * invoiced usage snapshots and ADR 0023 already decided what that means here:
 * the invoice is frozen at issue, so the number on it must come from a row
 * that cannot change, and that row is stamped with the line that quoted it
 * and can never be quoted again. A meter that revises history later writes a
 * *second* snapshot, and the correction is a credit note rather than an edit.
 *
 * **Only closed, uncharged periods.** A snapshot with an invoice item has
 * been billed; one for a period still running does not exist, because the
 * contract refuses to report one.
 *
 * **A currency mismatch is skipped rather than converted.** Money is never
 * converted in this product, so a meter priced in euros has no business on a
 * sterling invoice — it waits for one in its own currency, which is what the
 * renewal run's own grouping already guarantees for everything else.
 *
 * **Zero is a line, not nothing.** A customer inside their allowance gets a
 * line saying so with an amount of zero: a bandwidth line that disappears the
 * month somebody stayed inside their allowance reads as a billing mistake,
 * and it is the month they most want to see the figure.
 */
final readonly class QuoteUsage
{
    /**
     * @return array{lines: int, total: Money}
     */
    public function handle(Invoice $invoice, Service $service, int $position): array
    {
        $total = Money::zero($invoice->currency_code);
        $lines = 0;

        $snapshots = UsageSnapshot::query()
            ->withoutGlobalScope('organization')
            ->with('meter')
            ->where('service_id', $service->id)
            ->uncharged()
            ->orderBy('period_start')
            ->get();

        foreach ($snapshots as $snapshot) {
            $meter = $snapshot->meter;

            if ($meter === null || $meter->currency_code !== $invoice->currency_code) {
                // Money is never converted here. It waits for an invoice in
                // its own currency.
                continue;
            }

            $amount = $meter->charge($snapshot->amount());
            $total = $total->plus($amount);

            $item = InvoiceItem::query()->create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                // The snapshot, not the service: this line is about one
                // measured period and the way back to it is the row itself.
                'subject_type' => UsageSnapshot::class,
                'subject_id' => $snapshot->id,
                'description' => $this->describe($service, $snapshot, $meter),
                'quantity' => 1,
                'currency_code' => $invoice->currency_code,
                'unit_amount_minor' => $amount->minorUnits,
                'line_amount_minor' => $amount->minorUnits,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'period_start' => $snapshot->period_start->toDateString(),
                'period_end' => $snapshot->period_end->toDateString(),
                'position' => $position + $lines,
            ]);

            // Stamped before anything else can quote it. A snapshot with an
            // invoice item has been charged for, once and for ever.
            $snapshot->forceFill(['invoice_item_id' => $item->id])->save();

            $lines++;
        }

        return ['lines' => $lines, 'total' => $total];
    }

    /**
     * What the line says.
     *
     * The quantity and the allowance are in the words, because a customer
     * looking at a usage charge wants to know how far over they went and a
     * line that shows only money makes them ask.
     */
    private function describe(Service $service, UsageSnapshot $snapshot, UsageMeterRecord $meter): string
    {
        $unit = (string) __($snapshot->unit->labelKey());

        return (string) __('billing.usage.line', [
            'service' => $service->name,
            'meter' => $meter->meter_key,
            'quantity' => rtrim(rtrim(number_format($snapshot->amount(), 2, '.', ''), '0'), '.'),
            'unit' => $unit,
            'included' => rtrim(rtrim(number_format($meter->included(), 2, '.', ''), '0'), '.'),
            'period' => $snapshot->period_start->format('Y-m'),
        ]);
    }
}
