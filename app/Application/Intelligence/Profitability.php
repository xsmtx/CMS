<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Application\Reports\MoneyByCurrency;
use App\Domain\Intelligence\ProfitGrouping;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use App\Support\Organizations\OrganizationSubtree;
use Carbon\CarbonImmutable;

/**
 * What a month earned and what it cost to earn it (§21).
 *
 * **Revenue comes from the services, never from invoice lines.** A line
 * copies its description when it is written (ADR 0021), so grouping by it
 * merges two products renamed the same thing and splits one renamed last
 * March — the mistake the product revenue report was built to avoid, and the
 * same reason this report reads `services.recurring`.
 *
 * **It is a snapshot, not a history.** The figure is what the estate looks
 * like now, divided down to a month, against the costs recorded now. A cost
 * entry is a statement somebody edits when the price changes rather than an
 * append-only record, so a chart of margin over twelve months would redraw
 * itself whenever a typo was fixed.
 *
 * **A one-time price is skipped, never counted as zero**, which is the rule
 * `RecurringRevenueReport` already follows: a setup fee inside a monthly
 * figure overstates every month after the first.
 *
 * **Costs that reached no service stay out of every row and inside the
 * total.** An empty server belongs to no customer and no product, and a
 * report that dropped it would understate the month.
 */
final readonly class Profitability
{
    /** @var list<string> */
    private const array Earning = [
        'active',
        'grace_period',
        'cancel_pending',
    ];

    /*
     * A customer is an organization of its own, so a service, a domain, an
     * addon and a transaction all belong to the **customer's** organization
     * and never to the seller's. Narrowing through `customers.organization_id`
     * matched nothing at all on a real installation, and passed every test
     * because the fixture had forced the customer into the provider's own
     * organization — which `CustomerFactory` goes out of its way not to do.
     *
     * `OrganizationSubtree` is the one place that answers "whose customers
     * are these", which is exactly why it exists.
     */
    public function __construct(
        private OrganizationContext $organizations,
        private AllocateCosts $costs,
        private OrganizationSubtree $subtree,
    ) {}

    /**
     * @return array{
     *     rows: list<ProfitRow>,
     *     revenue: MoneyByCurrency,
     *     cost: MoneyByCurrency,
     *     unallocated: MoneyByCurrency,
     * }
     */
    public function forMonth(
        string $organizationId,
        CarbonImmutable $month,
        ProfitGrouping $grouping,
    ): array {
        $services = $this->services($organizationId);
        $allocated = $this->costs->run($organizationId, $month);

        /** @var array<string, array{label: string, revenue: MoneyByCurrency, cost: MoneyByCurrency, services: int}> $groups */
        $groups = [];

        $revenue = new MoneyByCurrency;
        $cost = new MoneyByCurrency;

        foreach ($services as $service) {
            [$key, $label] = $this->group($service, $grouping);

            $groups[$key] ??= [
                'label' => $label,
                'revenue' => new MoneyByCurrency,
                'cost' => new MoneyByCurrency,
                'services' => 0,
            ];

            $groups[$key]['services']++;

            $monthly = $this->monthlyRevenue($service);

            if ($monthly > 0) {
                $groups[$key]['revenue']->add($service->currency_code, $monthly);
                $revenue->add($service->currency_code, $monthly);
            }

            foreach ($allocated['shares'][$service->id] ?? [] as $share) {
                $groups[$key]['cost']->add($share->amount->currency->code, $share->amount->minorUnits);
                $cost->add($share->amount->currency->code, $share->amount->minorUnits);
            }
        }

        $unallocated = new MoneyByCurrency;

        foreach ($allocated['unallocated'] as $share) {
            $unallocated->add($share->amount->currency->code, $share->amount->minorUnits);
            // In the month's total, out of every row: it belongs to no
            // customer and no product, and dropping it would understate the
            // month.
            $cost->add($share->amount->currency->code, $share->amount->minorUnits);
        }

        $rows = array_map(
            static fn (string $key, array $group): ProfitRow => new ProfitRow(
                key: $key,
                label: $group['label'],
                revenue: $group['revenue'],
                cost: $group['cost'],
                services: $group['services'],
            ),
            array_keys($groups),
            array_values($groups),
        );

        /*
         * Largest first, by each row's own biggest single-currency figure.
         * Sorting across currencies would be sorting by nothing, and there
         * is no rate here to make one number out of several.
         */
        usort(
            $rows,
            static fn (ProfitRow $a, ProfitRow $b): int => self::largest($b->revenue) <=> self::largest($a->revenue),
        );

        return [
            'rows' => $rows,
            'revenue' => $revenue,
            'cost' => $cost,
            'unallocated' => $unallocated,
        ];
    }

    /**
     * The biggest single-currency figure in a total, for ordering only.
     *
     * Never shown: it is a number in one currency standing for a row that
     * may have several, which is exactly the figure this product refuses to
     * print.
     */
    private static function largest(MoneyByCurrency $money): int
    {
        $rows = $money->toArray();

        return $rows === [] ? 0 : abs($rows[0]['minor']);
    }

    /**
     * What this service brings in over one month.
     *
     * Integer division by the cycle's own length, which is what MRR has
     * meant in this product since the reports were written. A one-time price
     * has no months and is skipped rather than counted as zero.
     */
    private function monthlyRevenue(Service $service): int
    {
        $months = $service->billing_cycle?->months() ?? 0;

        if ($months <= 0) {
            return 0;
        }

        return intdiv($service->recurring_minor, $months);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function group(Service $service, ProfitGrouping $grouping): array
    {
        return match ($grouping) {
            ProfitGrouping::Customer => [
                $service->customer_id ?? 'none',
                $service->customer?->displayName() ?? '',
            ],
            ProfitGrouping::Product => [
                $service->product_id ?? 'none',
                $service->product->name ?? $service->name,
            ],
            ProfitGrouping::Server => [
                $service->server_id ?? 'none',
                $service->server->name ?? '',
            ],
        };
    }

    /**
     * @return list<Service>
     */
    private function services(string $organizationId): array
    {
        return $this->organizations->withoutBoundary(fn (): array => array_values(
            Service::query()
                ->withoutGlobalScope('organization')
                ->whereIn('status', self::Earning)
                ->whereIn('organization_id', $this->subtree->ids($organizationId))
                // A customer's name falls back through two relations, so it
                // is loaded rather than resolved per row.
                ->with([...Customer::displayNameWith('customer'), 'product', 'server'])
                ->get()
                ->all(),
        ));
    }
}
