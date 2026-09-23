<?php

declare(strict_types=1);

namespace App\Application\Resellers;

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the provider sold through its resellers.
 *
 * The other half of the question — "what did *I* sell" — is the dashboard a
 * reseller already has. Every screen in this panel is narrowed by the
 * organization boundary, so a reseller signing in sees their own customers,
 * their own recurring revenue and their own overdue invoices without a
 * reseller-specific report existing at all. Building a second one would be
 * building a second thing to keep in step.
 *
 * So this is the roll-up the provider cannot get from the boundary: one row
 * per reseller, because the provider's boundary is the whole tree and a total
 * across it answers nothing.
 *
 * **A customer's rows are attributed to its parent, and that works because
 * there is one level of resale.** `permittedChildTypes()` says a reseller
 * owns customers and nothing else, so the organization that owns an order is
 * a customer and its `parent_id` is the seller. This is the payoff of that
 * decision: attribution is a join rather than a recursive walk of `path`.
 *
 * **Money is grouped by currency and never summed across them.** A reseller
 * selling in lira and euros has two numbers, and there is no rate here to
 * turn them into one.
 *
 * The reseller list comes from a **scoped** read, and every aggregate is
 * narrowed to those ids. `DB::table()` carries no global scope, so the
 * boundary has to be inherited deliberately rather than assumed — and this is
 * where it is inherited.
 */
final readonly class ResellerPerformance
{
    public function __construct(private ResellerLedger $ledger) {}

    /**
     * One row per reseller, for a period.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $resellers = Organization::query()
            ->where('type', OrganizationType::Reseller->value)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        if ($resellers->isEmpty()) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $resellers->pluck('id')->all();

        $customers = $this->customerCounts($ids);
        $orders = $this->orderCounts($ids, $from, $to);
        $services = $this->activeServiceCounts($ids);
        $recurring = $this->monthlyRecurring($ids);
        $invoiced = $this->invoiced($ids, $from, $to);

        return array_values($resellers
            ->map(fn (Organization $reseller): array => [
                'id' => $reseller->id,
                'name' => $reseller->name,
                'slug' => $reseller->slug,
                'customers' => $customers[$reseller->id] ?? 0,
                'orders' => $orders[$reseller->id] ?? 0,
                'services' => $services[$reseller->id] ?? 0,
                'recurring' => $this->asMoneyList($recurring[$reseller->id] ?? []),
                'invoiced' => $this->asMoneyList($invoiced[$reseller->id] ?? []),
                'balances' => array_map(
                    static fn (Money $money): array => [
                        'currency' => $money->currency->code,
                        'amount' => $money->format(app()->getLocale()),
                        'minor' => $money->minorUnits,
                    ],
                    $this->ledger->balances($reseller->id),
                ),
            ])
            ->all());
    }

    /**
     * How many customers each reseller has.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function customerCounts(array $ids): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('organizations')
            ->select('parent_id')
            ->selectRaw('count(*) as total')
            ->whereIn('parent_id', $ids)
            ->where('type', OrganizationType::Customer->value)
            ->groupBy('parent_id')
            ->pluck('total', 'parent_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        return $counts;
    }

    /**
     * Orders placed in the period.
     *
     * By `created_at` rather than by when they were paid: this is a question
     * about selling, and an order that was placed and then abandoned is still
     * something the reseller did.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function orderCounts(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('orders')
            ->join('organizations', 'organizations.id', '=', 'orders.organization_id')
            ->select('organizations.parent_id')
            ->selectRaw('count(*) as total')
            ->whereIn('organizations.parent_id', $ids)
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('organizations.parent_id')
            ->pluck('total', 'parent_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        return $counts;
    }

    /**
     * Services that are active right now.
     *
     * Not "in the period": a service is a thing that either is or is not
     * running, and asking how many were running last March is a different
     * report from this one.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function activeServiceCounts(array $ids): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('services')
            ->join('organizations', 'organizations.id', '=', 'services.organization_id')
            ->select('organizations.parent_id')
            ->selectRaw('count(*) as total')
            ->whereIn('organizations.parent_id', $ids)
            ->where('services.status', ServiceStatus::Active->value)
            ->groupBy('organizations.parent_id')
            ->pluck('total', 'parent_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        return $counts;
    }

    /**
     * Recurring revenue, normalised to a month, per reseller and currency.
     *
     * The division happens in integer minor units, like every other piece of
     * arithmetic on money here — a yearly service at 1200.00 is 100.00 a
     * month and no float is involved. A one-time line has no monthly share to
     * take, so it is skipped rather than counted as zero.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, int>>
     */
    private function monthlyRecurring(array $ids): array
    {
        $rows = DB::table('services')
            ->join('organizations', 'organizations.id', '=', 'services.organization_id')
            ->select([
                'organizations.parent_id',
                'services.billing_cycle',
                'services.currency_code',
            ])
            ->selectRaw('sum(services.recurring_minor) as total')
            ->whereIn('organizations.parent_id', $ids)
            ->where('services.status', ServiceStatus::Active->value)
            ->groupBy('organizations.parent_id', 'services.billing_cycle', 'services.currency_code')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $cycle = BillingCycle::tryFrom((string) $row->billing_cycle);

            if (! $cycle instanceof BillingCycle) {
                continue;
            }

            $months = $cycle->months();

            if ($months < 1) {
                continue;
            }

            $reseller = (string) $row->parent_id;
            $currency = (string) $row->currency_code;

            $totals[$reseller][$currency] = ($totals[$reseller][$currency] ?? 0)
                + intdiv((int) $row->total, $months);
        }

        return $totals;
    }

    /**
     * What was invoiced in the period, per reseller and currency.
     *
     * Issued invoices only. A draft is not a document yet (ADR 0023), and a
     * report that counted drafts would be a report of what somebody was
     * thinking about.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, int>>
     */
    private function invoiced(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('invoices')
            ->join('organizations', 'organizations.id', '=', 'invoices.organization_id')
            ->select(['organizations.parent_id', 'invoices.currency_code'])
            ->selectRaw('sum(invoices.total_minor) as total')
            ->whereIn('organizations.parent_id', $ids)
            ->where('invoices.status', '!=', InvoiceStatus::Draft->value)
            ->whereBetween('invoices.issued_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('organizations.parent_id', 'invoices.currency_code')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->parent_id][(string) $row->currency_code] = (int) $row->total;
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $byCurrency
     * @return list<array<string, mixed>>
     */
    private function asMoneyList(array $byCurrency): array
    {
        $rows = [];

        foreach ($byCurrency as $currency => $minor) {
            $money = Money::ofMinor($minor, $currency);

            $rows[] = [
                'currency' => $currency,
                'amount' => $money->format(app()->getLocale()),
                'minor' => $minor,
            ];
        }

        return $rows;
    }
}
