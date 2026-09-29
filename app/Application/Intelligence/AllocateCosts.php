<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Domain\Intelligence\AllocationStrategy;
use App\Domain\Intelligence\CostScope;
use App\Domain\Intelligence\CostShare;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Intelligence\Models\CostEntry;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;

/**
 * What each service costs to run, in one month (§21).
 *
 * **Core ships no allocation.** Every figure here comes from a strategy an
 * operator chose on the cost entry itself, and every share says which
 * strategy and what it was weighted by. A margin whose arithmetic cannot be
 * checked is a margin somebody will believe when it is wrong.
 *
 * **A cost with nothing to share it is not hidden.** A server bought last
 * week with no accounts on it costs exactly as much as a full one, and a
 * report that quietly dropped it would understate the month — which is the
 * direction a cost report must never be wrong in. `unallocated()` is where
 * those land, and the screen shows them.
 *
 * **Nothing here touches the ledger.** A cost is what the provider paid
 * somebody else; the ledger is what customers paid this provider (ADR 0024),
 * and one table answering both questions is how a margin ends up in a
 * customer's balance.
 */
final readonly class AllocateCosts
{
    /**
     * Which services are on the estate for the purpose of sharing a cost.
     *
     * A suspended account still occupies the disk it is suspended on, and a
     * cancelling one is still there until it is not. Only `Terminated` and
     * the not-yet-created states are out.
     *
     * @var list<string>
     */
    private const array Occupying = [
        'active',
        'suspended',
        'grace_period',
        'cancel_pending',
    ];

    public function __construct(private OrganizationContext $organizations) {}

    /**
     * Every service's shares, keyed by service id.
     *
     * @return array<string, list<CostShare>>
     */
    public function forMonth(string $organizationId, CarbonImmutable $month): array
    {
        return $this->run($organizationId, $month)['shares'];
    }

    /**
     * Costs that reached no service at all.
     *
     * @return list<CostShare>
     */
    public function unallocated(string $organizationId, CarbonImmutable $month): array
    {
        return $this->run($organizationId, $month)['unallocated'];
    }

    /**
     * @return array{shares: array<string, list<CostShare>>, unallocated: list<CostShare>}
     */
    public function run(string $organizationId, CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();

        $services = $this->services($organizationId);
        /** @var array<string, list<CostShare>> $shares */
        $shares = [];
        /** @var list<CostShare> $unallocated */
        $unallocated = [];

        foreach ($this->entries($organizationId) as $entry) {
            if (! $entry->appliesIn($start)) {
                continue;
            }

            $monthly = $entry->period->monthlyMinor($entry->amount_minor);

            if ($monthly === 0) {
                continue;
            }

            $whole = Money::ofMinor($monthly, $entry->currency_code);
            $matching = $this->matching($entry, $services);

            if ($matching === []) {
                // A server with nothing on it costs exactly as much as a
                // full one. Dropping it would understate the month.
                $unallocated[] = new CostShare(
                    costEntryId: $entry->id,
                    label: $entry->label,
                    amount: $whole,
                    strategy: $entry->strategy,
                    across: 0,
                );

                continue;
            }

            foreach ($this->split($entry, $whole, $matching) as $serviceId => $share) {
                $shares[$serviceId][] = $share;
            }
        }

        return ['shares' => $shares, 'unallocated' => $unallocated];
    }

    /**
     * One cost across the services that share it.
     *
     * @param  list<Service>  $matching
     * @return array<string, CostShare>
     */
    private function split(CostEntry $entry, Money $whole, array $matching): array
    {
        if ($entry->strategy === AllocationStrategy::Weighted && $entry->metric !== null) {
            $weighted = $this->weighted($entry, $whole, $matching);

            if ($weighted !== []) {
                return $weighted;
            }
        }

        $amounts = $whole->allocateEvenly(count($matching));
        $split = [];

        foreach ($matching as $index => $service) {
            $split[$service->id] = new CostShare(
                costEntryId: $entry->id,
                label: $entry->label,
                amount: $amounts[$index],
                // Says `even` even where the entry asked for weighted and
                // nothing was measured: the figure is what it is, and a row
                // claiming a weighting that did not happen is the lie this
                // whole class is arranged against.
                strategy: AllocationStrategy::Even,
                across: count($matching),
            );
        }

        return $split;
    }

    /**
     * In proportion to a metric, with the unmeasured assumed to be average.
     *
     * Zero for a service nothing reported is the answer that makes a loss
     * disappear — the unmonitored box would carry no cost at all and look
     * like the most profitable thing on the estate. `ScorePlacement` settled
     * this once; the same reasoning applies to money.
     *
     * @param  list<Service>  $matching
     * @return array<string, CostShare>
     */
    private function weighted(CostEntry $entry, Money $whole, array $matching): array
    {
        $metric = $entry->metric?->value;

        if ($metric === null) {
            return [];
        }

        $readings = $this->readings($matching, $metric);

        if ($readings === []) {
            // Nothing is measured, so there is nothing to weight by.
            return [];
        }

        $average = array_sum($readings) / count($readings);

        $weights = [];
        $assumed = [];

        foreach ($matching as $service) {
            $value = $readings[$service->id] ?? $average;
            $assumed[$service->id] = ! isset($readings[$service->id]);
            // Integer weights, because `allocate()` takes them and because a
            // float weight would put a rounding decision inside the split
            // rather than at the end of it.
            $weights[] = max(1, (int) round($value * 1000));
        }

        $total = array_sum($weights);
        $amounts = $whole->allocate($weights);

        if ($amounts === []) {
            return [];
        }

        $split = [];

        foreach ($matching as $index => $service) {
            $split[$service->id] = new CostShare(
                costEntryId: $entry->id,
                label: $entry->label,
                amount: $amounts[$index],
                strategy: AllocationStrategy::Weighted,
                across: count($matching),
                metric: $metric,
                weight: (float) $weights[$index],
                weightTotal: (float) $total,
                assumed: $assumed[$service->id],
            );
        }

        return $split;
    }

    /**
     * The current reading of one metric for each of these services.
     *
     * A service's node key is its own id (`ProjectCoreResources`), so this
     * is one query rather than one per service.
     *
     * @param  list<Service>  $matching
     * @return array<string, float>
     */
    private function readings(array $matching, string $metric): array
    {
        $ids = array_map(static fn (Service $service): string => $service->id, $matching);

        $nodes = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->whereIn('node_key', $ids)
            ->whereNull('retired_at')
            ->pluck('node_key', 'id');

        if ($nodes->isEmpty()) {
            return [];
        }

        $readings = [];

        foreach (
            ResourceMetric::query()
                ->withoutGlobalScope('organization')
                ->whereIn('resource_node_id', $nodes->keys())
                ->where('metric', $metric)
                ->get() as $row
        ) {
            if ($row->isStale()) {
                // A machine that stopped reporting is a monitoring problem,
                // not a service using nothing. Left out, so it is assumed to
                // be average rather than free.
                continue;
            }

            $key = $nodes[$row->resource_node_id] ?? null;

            if (is_string($key)) {
                $readings[$key] = $row->value;
            }
        }

        return $readings;
    }

    /**
     * The services one cost entry is shared across.
     *
     * @param  list<Service>  $services
     * @return list<Service>
     */
    private function matching(CostEntry $entry, array $services): array
    {
        return match ($entry->scope) {
            CostScope::Installation => $services,
            CostScope::Server => array_values(array_filter(
                $services,
                static fn (Service $service): bool => $service->server_id === $entry->subject_id,
            )),
            CostScope::Product => array_values(array_filter(
                $services,
                static fn (Service $service): bool => $service->product_id === $entry->subject_id,
            )),
            // A licence against a server is that server's; a licence against
            // nothing is the installation's.
            CostScope::Licence => $entry->subject_type === Server::class
                ? array_values(array_filter(
                    $services,
                    static fn (Service $service): bool => $service->server_id === $entry->subject_id,
                ))
                : ($entry->subject_type === Product::class
                    ? array_values(array_filter(
                        $services,
                        static fn (Service $service): bool => $service->product_id === $entry->subject_id,
                    ))
                    : $services),
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
                ->whereIn('status', self::Occupying)
                ->whereHas('customer', static fn ($query) => $query
                    ->where('organization_id', $organizationId))
                ->get()
                ->all(),
        ));
    }

    /**
     * @return list<CostEntry>
     */
    private function entries(string $organizationId): array
    {
        return array_values(CostEntry::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->orderBy('label')
            ->get()
            ->all());
    }
}
