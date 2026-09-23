<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What recurs, per month, and what happened to it.
 *
 * **MRR here means one thing and the screen says which**: the sum of every
 * active service's recurring amount, divided down to a month. It is a snapshot
 * of today, not an average of the period — and those are different numbers that
 * get conflated constantly.
 *
 * **ARR is MRR × 12 and is labelled as such.** It is not computed from a year of
 * invoices. Both are legitimate figures and they answer different questions; a
 * report that printed one and called it the other would be the classic reporting
 * lie, and an operator raising money on it would find out in due diligence.
 *
 * The division is integer, on minor units: a yearly service at 1200.00 is 100.00
 * a month exactly. A one-time line has no monthly share and is skipped rather
 * than counted as zero — counting it would put a setup fee in a recurring figure.
 *
 * **Movement is measured on services, not on money that arrived.** "Added" is
 * services that started in the period; "lost" is services that terminated in it.
 * That makes the two numbers comparable and it makes each of them a list an
 * operator can open, which is the only way a churn figure is ever useful.
 */
final readonly class RecurringRevenueReport
{
    /**
     * @return array<string, mixed>
     */
    public function handle(ReportPeriod $period): array
    {
        return [
            'mrr' => $this->monthly()->toArray(app()->getLocale()),
            // Twelve times the month, and labelled as such on the screen.
            'arr' => $this->monthly()->multipliedBy(12)->toArray(app()->getLocale()),
            'active' => $this->countByStatus(ServiceStatus::Active),
            'suspended' => $this->countByStatus(ServiceStatus::Suspended),
            'added' => $this->started($period),
            'lost' => $this->terminated($period),
            'addedRecurring' => $this->startedRecurring($period)->toArray(app()->getLocale()),
            'lostRecurring' => $this->terminatedRecurring($period)->toArray(app()->getLocale()),
        ];
    }

    /**
     * Money in, by month, for the chart.
     *
     * From the ledger rather than from invoices: the ledger is the truth
     * (ADR 0024), and an invoice's date is when it was issued rather than when
     * it was paid. A revenue chart built on issue dates is a chart of intent.
     *
     * @return array<string, int> keyed `YYYY-MM`, in minor units of the given currency
     */
    public function collectedByMonth(ReportPeriod $period, string $currency): array
    {
        $organizationId = app(OrganizationContext::class)->id();

        // `DB::table` carries no global scope, so the boundary is applied by
        // hand — a report that leaked another reseller's revenue would be the
        // worst possible place to leak one.
        $rows = DB::table('transactions')
            ->selectRaw("date_format(occurred_at, '%Y-%m') as month")
            ->selectRaw('sum(amount_minor) as total')
            ->where('kind', 'payment')
            ->where('currency_code', strtoupper($currency))
            ->whereBetween('occurred_at', [$period->from, $period->to])
            ->when(
                $organizationId !== null,
                fn ($query) => $query->whereIn(
                    'organization_id',
                    DB::table('organizations')
                        ->select('id')
                        ->where('id', $organizationId)
                        ->orWhere('path', 'like', "%/{$organizationId}/%"),
                ),
            )
            ->groupBy('month')
            ->get();

        $byMonth = [];

        foreach ($period->months() as $month) {
            // Zero rather than absent: a chart that skipped a quiet month would
            // compress a year into nine and lie about the shape.
            $byMonth[$month] = 0;
        }

        foreach ($rows as $row) {
            $month = (string) $row->month;

            if (array_key_exists($month, $byMonth)) {
                $byMonth[$month] = (int) $row->total;
            }
        }

        return $byMonth;
    }

    /**
     * Every active service's recurring amount, normalised to a month.
     */
    private function monthly(): MoneyByCurrency
    {
        $totals = new MoneyByCurrency;

        // `toBase()` keeps the model's global scopes — so a reseller reading this
        // sees their own subtree — while making the aggregate typeable.
        $rows = Service::query()
            ->toBase()
            ->select(['billing_cycle', 'currency_code'])
            ->selectRaw('sum(recurring_minor) as total')
            ->where('status', ServiceStatus::Active->value)
            ->groupBy('billing_cycle', 'currency_code')
            ->get();

        foreach ($rows as $row) {
            $cycle = BillingCycle::tryFrom((string) $row->billing_cycle);
            $months = $cycle?->months() ?? 0;

            // A one-time line does not recur, so it has no monthly share to
            // take. Counting it would put a setup fee in a recurring figure.
            if ($months < 1) {
                continue;
            }

            $totals->add((string) $row->currency_code, intdiv((int) $row->total, $months));
        }

        return $totals;
    }

    private function countByStatus(ServiceStatus $status): int
    {
        return Service::query()->where('status', $status->value)->count();
    }

    private function started(ReportPeriod $period): int
    {
        return Service::query()
            ->whereBetween('starts_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->count();
    }

    private function terminated(ReportPeriod $period): int
    {
        return Service::query()
            ->whereBetween('terminated_at', [$period->from, $period->to])
            ->count();
    }

    private function startedRecurring(ReportPeriod $period): MoneyByCurrency
    {
        return $this->recurringWhere(
            Service::query()
                ->toBase()
                ->whereBetween('starts_on', [$period->from->toDateString(), $period->to->toDateString()]),
        );
    }

    /**
     * What terminated in the period was worth per month.
     *
     * The service's own recurring amount, whatever its status is now: a
     * terminated service still tells you what it used to bring in, and that is
     * the number "we lost 400.00 a month" is made of.
     */
    private function terminatedRecurring(ReportPeriod $period): MoneyByCurrency
    {
        return $this->recurringWhere(
            Service::query()
                ->toBase()
                ->whereBetween('terminated_at', [$period->from, $period->to]),
        );
    }

    /**
     * @param  Builder  $query
     */
    private function recurringWhere($query): MoneyByCurrency
    {
        $totals = new MoneyByCurrency;

        $rows = $query
            ->select(['billing_cycle', 'currency_code'])
            ->selectRaw('sum(recurring_minor) as total')
            ->groupBy('billing_cycle', 'currency_code')
            ->get();

        foreach ($rows as $row) {
            $months = BillingCycle::tryFrom((string) $row->billing_cycle)?->months() ?? 0;

            if ($months < 1) {
                continue;
            }

            $totals->add((string) $row->currency_code, intdiv((int) $row->total, $months));
        }

        return $totals;
    }
}
