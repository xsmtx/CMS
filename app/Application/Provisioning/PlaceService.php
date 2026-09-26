<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Provisioning\Exceptions\PlacementFailed;
use App\Domain\Provisioning\PlacementDecision;
use App\Domain\Provisioning\PlacementStrategy;
use App\Domain\Provisioning\ServerStatus;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServicePlacement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Which node a service goes on.
 *
 * Three rules hold whatever the strategy says, because they are about
 * whether a node *can* take work rather than which one *should*:
 *
 * - A node not in `active` is never chosen. Maintenance is an operator's
 *   decision and the platform does not overrule it.
 * - A node at or over capacity is never chosen, and reaching capacity marks
 *   it `full` so the next placement does not have to count again.
 * - If nothing can be chosen, this fails loudly. A service placed on a node
 *   that cannot hold it is a support ticket tomorrow; a service that failed
 *   to place is an operator's queue today.
 *
 * Capacity is counted from services that still exist at the provider: a
 * suspended account still occupies a slot, a terminated one does not.
 */
final readonly class PlaceService
{
    public function __construct(
        private ScorePlacement $scores,
    ) {}

    /**
     * @param  Service|null  $for  the service being placed, when there is one:
     *                             what makes anti-affinity and a written reason
     *                             possible
     */
    public function handle(ServerGroup $group, ?string $preferredRegion = null, ?Service $for = null): Server
    {
        $candidates = $this->candidates($group);

        if ($candidates->isEmpty()) {
            throw PlacementFailed::noServers($group->name);
        }

        $used = $this->usageFor($candidates);

        $withCapacity = $candidates->filter(
            fn (Server $server): bool => $server->hasCapacity($used[$server->id] ?? 0),
        );

        if ($withCapacity->isEmpty()) {
            $this->markFull($candidates, $used);

            throw PlacementFailed::noCapacity($group->name);
        }

        // Scored for every strategy, not only for the scored one. The readings
        // are what an operator wants beside the choice whatever made it - "the
        // group is set to fewest accounts, and here is what the node's disk was
        // doing at the time" is the useful sentence. It costs two queries on a
        // path that runs once per service.
        $decisions = $for instanceof Service || $group->placement_strategy === PlacementStrategy::Scored
            ? $this->scores->forServers($withCapacity, $used, $preferredRegion, $for?->customer_id)
            : [];

        $chosen = match ($group->placement_strategy) {
            PlacementStrategy::LeastAccounts => $this->leastAccounts($withCapacity, $used),
            PlacementStrategy::Weighted => $this->weighted($withCapacity, $used),
            PlacementStrategy::CapacityAware => $this->capacityAware($withCapacity, $used),
            PlacementStrategy::RegionAware => $this->regionAware($withCapacity, $used, $preferredRegion),
            PlacementStrategy::Scored => $this->scored($withCapacity, $used, $decisions),
            PlacementStrategy::Manual => throw PlacementFailed::manualGroup($group->name),
        };

        $this->markFullIfReached($chosen, ($used[$chosen->id] ?? 0) + 1);

        if ($for instanceof Service) {
            $this->record($for, $chosen, $group->placement_strategy, $decisions[$chosen->id] ?? null);
        }

        return $chosen;
    }

    /**
     * The best node over every factor the installation can measure.
     *
     * The ordering falls back to fewest accounts when two nodes score
     * identically, which is what an installation with no monitoring at all
     * looks like: every node answers on the same three factors, every score
     * ties, and the dull correct default decides. That is the behaviour an
     * operator who turns this on before configuring a monitoring adapter
     * should get - not an arbitrary node.
     *
     * @param  Collection<int, Server>  $servers
     * @param  array<string, int>  $used
     * @param  array<string, PlacementDecision>  $decisions
     */
    private function scored(Collection $servers, array $used, array $decisions): Server
    {
        return $servers->sortBy(
            // Score, then accounts, then the id. The measures go in front of
            // the id rather than each carrying one: `key()` ends with the
            // server's own id, so two of them concatenated would decide every
            // tie on the first id and the accounts half would never be read.
            fn (Server $server): string => sprintf(
                '%015.6f|%015.6f|%s',
                1.0 - ($decisions[$server->id]?->score() ?? 0.0),
                $used[$server->id] ?? 0,
                $server->id,
            ),
        )->firstOrFail();
    }

    /**
     * Write down why.
     *
     * Append-only, and it never throws into the placement: a decision that was
     * made and not recorded is a gap in an audit trail, and a provisioning job
     * that failed because the audit trail could not be written is an outage.
     */
    private function record(
        Service $service,
        Server $chosen,
        PlacementStrategy $strategy,
        ?PlacementDecision $decision,
    ): void {
        try {
            ServicePlacement::query()->create([
                'organization_id' => $service->organization_id,
                'service_id' => $service->id,
                'server_id' => $chosen->id,
                'strategy' => $strategy->value,
                'server_name' => $chosen->name,
                'score' => $decision?->score(),
                'candidates' => $decision->candidates ?? 1,
                'factors' => $decision?->toArray(),
                'decided_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return Collection<int, Server>
     */
    private function candidates(ServerGroup $group): Collection
    {
        return $group->servers()
            ->where('status', ServerStatus::Active->value)
            ->get();
    }

    /**
     * How many services each candidate is already holding.
     *
     * One query for the whole set rather than one per node: placement runs
     * inside a job that a busy installation runs constantly.
     *
     * @param  Collection<int, Server>  $servers
     * @return array<string, int>
     */
    private function usageFor(Collection $servers): array
    {
        /** @var array<string, int> $counts */
        $counts = Service::query()
            ->whereIn('server_id', $servers->pluck('id')->all())
            ->whereNot('status', 'terminated')
            ->selectRaw('server_id, COUNT(*) as total')
            ->groupBy('server_id')
            ->get()
            ->mapWithKeys(static fn (Service $row): array => [
                (string) $row->getAttribute('server_id') => (int) $row->getAttribute('total'),
            ])
            ->all();

        return $counts;
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @param  array<string, int>  $used
     */
    private function leastAccounts(Collection $servers, array $used): Server
    {
        // A composite key rather than a multi-sort: Laravel reads a callable
        // inside `sortBy([...])` as a two-argument comparator, which silently
        // sorts by something other than what it looks like.
        return $servers->sortBy(
            fn (Server $server): string => $this->key($used[$server->id] ?? 0, $server),
        )->firstOrFail();
    }

    /**
     * Fewest accounts per unit of weight.
     *
     * A node with weight 4 takes four times as many services as one with
     * weight 1 before either is preferred, which is what an operator means
     * by "that box is bigger".
     *
     * @param  Collection<int, Server>  $servers
     * @param  array<string, int>  $used
     */
    private function weighted(Collection $servers, array $used): Server
    {
        return $servers->sortBy(
            fn (Server $server): string => $this->key(
                ($used[$server->id] ?? 0) / max($server->weight, 1),
                $server,
            ),
        )->firstOrFail();
    }

    /**
     * The most headroom left as a share of capacity.
     *
     * A node with no declared limit has unlimited headroom, so it is only
     * chosen once every bounded node is fuller than it.
     *
     * @param  Collection<int, Server>  $servers
     * @param  array<string, int>  $used
     */
    private function capacityAware(Collection $servers, array $used): Server
    {
        // Sorted ascending on "how full", which is the same order as
        // descending on headroom and keeps one key-building rule.
        return $servers->sortBy(
            fn (Server $server): string => $this->key(
                $server->max_services === 0
                    ? 0.0
                    : ($used[$server->id] ?? 0) / $server->max_services,
                $server,
            ),
        )->firstOrFail();
    }

    /**
     * The customer's region if anything is there, and least accounts
     * otherwise.
     *
     * Falling back rather than failing is deliberate: a customer in a
     * region with no capacity is better served somewhere else than not at
     * all, and the service records where it actually landed.
     *
     * @param  Collection<int, Server>  $servers
     * @param  array<string, int>  $used
     */
    private function regionAware(Collection $servers, array $used, ?string $region): Server
    {
        if ($region !== null) {
            $local = $servers->filter(
                fn (Server $server): bool => $server->region !== null
                    && strcasecmp($server->region, $region) === 0,
            );

            if ($local->isNotEmpty()) {
                return $this->leastAccounts($local, $used);
            }
        }

        return $this->leastAccounts($servers, $used);
    }

    /**
     * A sortable key: the measure first, the id as a stable tiebreak.
     *
     * Zero-padded so that string comparison orders numbers correctly, and
     * deterministic so that two placements a second apart do not depend on
     * the order rows came back in.
     */
    private function key(float $measure, Server $server): string
    {
        return sprintf('%015.6f|%s', $measure, $server->id);
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @param  array<string, int>  $used
     */
    private function markFull(Collection $servers, array $used): void
    {
        foreach ($servers as $server) {
            $this->markFullIfReached($server, $used[$server->id] ?? 0);
        }
    }

    private function markFullIfReached(Server $server, int $used): void
    {
        if ($server->max_services === 0 || $used < $server->max_services) {
            return;
        }

        // A fact the platform worked out, not an operator's decision:
        // `full` is a separate state from `maintenance` so that one cannot
        // silently clear the other.
        $server->forceFill(['status' => ServerStatus::Full->value])->save();
    }
}
