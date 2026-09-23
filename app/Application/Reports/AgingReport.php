<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * What is owed, and for how long.
 *
 * The report an operator opens before deciding who to telephone, so the buckets
 * are the ones a credit controller thinks in: current, 1–30, 31–60, 61–90, over
 * 90.
 *
 * **Measured from the due date, not the issue date.** An invoice issued ninety
 * days ago with sixty-day terms is thirty days overdue, not ninety, and a report
 * that said otherwise would have somebody chasing a customer who has done
 * nothing wrong.
 *
 * **An invoice with no due date gets its own bucket.** Dropping it into
 * "current" would hide it, and there is no honest arithmetic to do with a
 * missing date — the bucket exists so that an operator sees the data problem
 * rather than a number that is quietly short.
 *
 * **The balance is what is outstanding**, not the total: a partially paid
 * invoice ages by what is left on it. `total_minor - paid_minor`, in integer
 * minor units, per currency.
 */
final readonly class AgingReport
{
    /**
     * @return array<string, mixed>
     */
    public function handle(?CarbonImmutable $asOf = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();

        $buckets = [
            'current' => new MoneyByCurrency,
            '1_30' => new MoneyByCurrency,
            '31_60' => new MoneyByCurrency,
            '61_90' => new MoneyByCurrency,
            'over_90' => new MoneyByCurrency,
            'no_due_date' => new MoneyByCurrency,
        ];

        $counts = array_fill_keys(array_keys($buckets), 0);

        $rows = Invoice::query()
            ->toBase()
            ->select(['currency_code', 'due_on', 'total_minor', 'paid_minor'])
            ->whereIn('status', InvoiceStatus::owed())
            ->get();

        foreach ($rows as $row) {
            // What is left on it. A partially paid invoice ages by the
            // remainder, not by the total.
            $outstanding = (int) $row->total_minor - (int) $row->paid_minor;

            if ($outstanding <= 0) {
                // A `paid_minor` that covers the total on an invoice still
                // marked owed is a cache that has not caught up. Not this
                // report's business, and not something to show as debt.
                continue;
            }

            $bucket = $this->bucket($row->due_on, $asOf);

            $buckets[$bucket]->add((string) $row->currency_code, $outstanding);
            $counts[$bucket]++;
        }

        $total = new MoneyByCurrency;

        foreach ($buckets as $bucket) {
            foreach ($bucket->currencies() as $currency) {
                $total->add($currency, $bucket->minorFor($currency));
            }
        }

        return [
            'asOf' => $asOf->toDateString(),
            'buckets' => array_map(
                static fn (MoneyByCurrency $bucket): array => $bucket->toArray(app()->getLocale()),
                $buckets,
            ),
            'counts' => $counts,
            'total' => $total->toArray(app()->getLocale()),
        ];
    }

    /**
     * Which bucket a due date falls in.
     */
    private function bucket(mixed $dueOn, CarbonImmutable $asOf): string
    {
        if (! is_string($dueOn) || $dueOn === '') {
            // Its own bucket, so the data problem is visible rather than
            // hidden inside "current".
            return 'no_due_date';
        }

        $due = CarbonImmutable::parse($dueOn)->startOfDay();

        if ($due->greaterThanOrEqualTo($asOf)) {
            return 'current';
        }

        $days = (int) $due->diffInDays($asOf, absolute: true);

        return match (true) {
            $days <= 30 => '1_30',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => 'over_90',
        };
    }
}
