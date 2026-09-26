<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Infrastructure\CapacityForecast;
use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Provisioning\PlacementComponent;
use App\Domain\Provisioning\PlacementDecision;
use App\Domain\Provisioning\PlacementFactor;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use Illuminate\Support\Collection;

/**
 * How good each node would be, and why.
 *
 * This is §4's placement engine, and the honest description of it is a weighted
 * mean over the readings the Resource Graph happens to hold. There is no model
 * here and there is deliberately nothing to tune beyond the weights on
 * `PlacementFactor`: a scoring function an operator cannot reproduce on paper is
 * one they will override every time it surprises them, and then the placement
 * engine is an operator with a spreadsheet.
 *
 * Three rules hold the whole thing up:
 *
 * **A reading nobody has taken is not a reading of zero, and it is not a free
 * pass either.** Every factor may decline; a factor at least one candidate
 * answered is then filled in for the rest with what those candidates averaged,
 * marked `assumed`. Scoring an unreported factor as zero would make the
 * unmonitored node the worst on the list, and dropping it from that node's mean
 * would judge it on how empty it is — which on a fresh box is the best score
 * there is. Both send every service to the one machine nobody can see. A factor
 * *no* candidate answered is dropped entirely, so an installation with no
 * monitoring scores exactly as it did before this existed: on accounts, region
 * and affinity.
 *
 * **A discovered fact never refuses a placement.** Graph health pulls a node
 * down hard, but it cannot take it out of the running: a monitoring adapter that
 * breaks at three in the morning would otherwise empty the candidate set and
 * fail every provisioning job on the installation. Only an operator's own row —
 * `maintenance`, `full` — is allowed to refuse, and `PlaceService` does that
 * before anything is scored.
 *
 * **Relative factors are relative to the candidates.** I/O and bandwidth have no
 * natural ceiling, so they are scored against the busiest node in the running
 * rather than against a number this code made up. That makes them useless for
 * "is this node in trouble" and exactly right for "which of these five".
 */
final readonly class ScorePlacement
{
    /**
     * Nodes whose disk fills within this are pulled all the way down.
     *
     * A quarter, because that is the window the forecast itself works in and
     * because a node filling inside it will be somebody's incident before the
     * service placed on it is out of its first billing cycle.
     */
    private const int GROWTH_HORIZON_DAYS = 90;

    public function __construct(
        private CapacityForecast $forecast,
    ) {}

    /**
     * @param  Collection<int, Server>  $servers  the nodes still in the running
     * @param  array<string, int>  $used  services already on each, by server id
     * @return array<string, PlacementDecision>
     */
    public function forServers(
        Collection $servers,
        array $used,
        ?string $preferredRegion = null,
        ?string $customerId = null,
    ): array {
        if ($servers->isEmpty()) {
            return [];
        }

        $nodes = $this->nodesFor($servers);
        $metrics = $this->metricsFor($nodes);
        $affinity = $this->affinityFor($servers, $customerId);

        $peaks = [
            PlacementFactor::Io->value => $this->peak($metrics, MetricKind::DiskIops),
            PlacementFactor::Bandwidth->value => $this->peak($metrics, MetricKind::NetworkOut),
        ];

        $answers = [];

        foreach ($servers as $server) {
            $node = $nodes[$server->id] ?? null;
            $nodeId = $node['id'] ?? null;
            $readings = $nodeId === null ? [] : ($metrics[$nodeId] ?? []);

            $components = array_filter([
                $this->accounts($server, $used[$server->id] ?? 0),
                $this->ratio(PlacementFactor::Cpu, $readings, MetricKind::CpuUtilisation),
                $this->share(PlacementFactor::Memory, $readings, MetricKind::MemoryUsed, MetricKind::MemoryTotal),
                $this->share(PlacementFactor::Disk, $readings, MetricKind::DiskUsed, MetricKind::DiskTotal),
                $this->relative(PlacementFactor::Io, $readings, MetricKind::DiskIops, $peaks[PlacementFactor::Io->value]),
                $this->relative(PlacementFactor::Bandwidth, $readings, MetricKind::NetworkOut, $peaks[PlacementFactor::Bandwidth->value]),
                $this->health($node['health'] ?? null),
                $this->region($server, $preferredRegion),
                $nodeId === null ? null : $this->growth($nodeId),
                $customerId === null ? null : $this->antiAffinity($server, $affinity),
            ]);

            $keyed = [];

            foreach ($components as $component) {
                $keyed[$component->factor->value] = $component;
            }

            $answers[$server->id] = $keyed;
        }

        return $this->assume($answers, $servers->count());
    }

    /**
     * Fill in what a candidate did not answer with what the others averaged.
     *
     * The alternative is to take the mean over each node's own factors, and it
     * is a trap: a node nothing monitors then answers only "how many accounts
     * am I holding", which on a fresh box is the best score on the list. So
     * every service lands on the one machine nobody can see - the same failure
     * as scoring absence at zero, arrived at from the other side.
     *
     * A factor no candidate answered is dropped entirely rather than assumed:
     * there is nothing to average, and an installation with no monitoring at all
     * should score exactly as it did before this code existed.
     *
     * @param  array<string, array<string, PlacementComponent>>  $answers
     * @return array<string, PlacementDecision>
     */
    private function assume(array $answers, int $candidates): array
    {
        $totals = [];
        $counts = [];

        foreach ($answers as $components) {
            foreach ($components as $slug => $component) {
                $totals[$slug] = ($totals[$slug] ?? 0.0) + $component->score;
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
        }

        $decisions = [];

        foreach ($answers as $serverId => $components) {
            foreach ($counts as $slug => $count) {
                if (isset($components[$slug])) {
                    continue;
                }

                $factor = PlacementFactor::from($slug);

                $components[$slug] = new PlacementComponent(
                    $factor,
                    score: $totals[$slug] / $count,
                    assumed: true,
                );
            }

            ksort($components);

            $decisions[$serverId] = new PlacementDecision(
                serverId: $serverId,
                components: array_values($components),
                candidates: $candidates,
            );
        }

        return $decisions;
    }

    /**
     * The graph node for each candidate, by server id.
     *
     * The boundary is escaped on purpose and it is safe here for one reason:
     * the servers were fetched through the group relation, which is scoped, so
     * the ids are already inside the caller's subtree. A node belongs to the
     * same organization as the server it points at, and placement runs inside a
     * queued job where the context may not be set at all.
     *
     * @param  Collection<int, Server>  $servers
     * @return array<string, array{id: string, health: string}>
     */
    private function nodesFor(Collection $servers): array
    {
        /** @var array<string, array{id: string, health: string}> $nodes */
        $nodes = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('kind', ResourceKind::Server)
            ->whereNull('retired_at')
            ->whereIn('subject_id', $servers->pluck('id')->all())
            ->get()
            ->mapWithKeys(static fn (ResourceNode $node): array => [
                (string) $node->getAttribute('subject_id') => ['id' => $node->id, 'health' => $node->health],
            ])
            ->all();

        return $nodes;
    }

    /**
     * Every reading for those nodes, by node then metric.
     *
     * `resource_metrics` holds the present value only (§14), so this is one
     * query for the whole candidate set and there is no window to choose.
     *
     * @param  array<string, array{id: string, health: string}>  $nodes
     * @return array<string, array<string, float>>
     */
    private function metricsFor(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        $readings = [];

        $rows = ResourceMetric::query()
            ->withoutGlobalScope('organization')
            ->whereIn('resource_node_id', array_column($nodes, 'id'))
            ->get();

        foreach ($rows as $row) {
            // A metric slug this version has no member for is dropped rather
            // than scored: the normalizer counts those, and a reading nobody
            // can name is not a reading a placement should act on.
            if (! $row->metric instanceof MetricKind) {
                continue;
            }

            $readings[(string) $row->getAttribute('resource_node_id')][$row->metric->value] = (float) $row->value;
        }

        return $readings;
    }

    /**
     * How many of this customer's services each candidate already holds.
     *
     * @param  Collection<int, Server>  $servers
     * @return array<string, int>
     */
    private function affinityFor(Collection $servers, ?string $customerId): array
    {
        if ($customerId === null) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = Service::query()
            ->where('customer_id', $customerId)
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
     * The busiest reading of one metric across the candidates.
     *
     * @param  array<string, array<string, float>>  $metrics
     */
    private function peak(array $metrics, MetricKind $metric): ?float
    {
        $peak = null;

        foreach ($metrics as $readings) {
            $value = $readings[$metric->value] ?? null;

            if ($value === null) {
                continue;
            }

            $peak = $peak === null ? $value : max($peak, $value);
        }

        return $peak;
    }

    /**
     * Fullness in accounts per unit of weight.
     *
     * The one factor that always answers, because it is counted from rows this
     * product owns. A node with no declared limit is compared on the raw count
     * against an arbitrary but stated scale: fifty accounts per unit of weight
     * is treated as full for scoring, which only ever decides an ordering
     * between nodes and never refuses one.
     */
    private function accounts(Server $server, int $used): PlacementComponent
    {
        $perWeight = $used / max($server->weight, 1);

        $ceiling = $server->max_services > 0
            ? $server->max_services / max($server->weight, 1)
            : 50.0;

        return new PlacementComponent(
            PlacementFactor::Accounts,
            score: $this->clamp(1.0 - ($perWeight / max($ceiling, 1.0))),
            measure: $perWeight,
        );
    }

    /**
     * A reading that is already a ratio of its own ceiling.
     *
     * @param  array<string, float>  $readings
     */
    private function ratio(PlacementFactor $factor, array $readings, MetricKind $metric): ?PlacementComponent
    {
        $value = $readings[$metric->value] ?? null;

        if ($value === null) {
            return null;
        }

        return new PlacementComponent($factor, score: $this->clamp(1.0 - $value), measure: $value);
    }

    /**
     * A used-of-total pair, which is the only honest way to read bytes.
     *
     * Used without total is declined rather than guessed at — the same rule the
     * forecast follows, and for the same reason: a ceiling nobody reported is a
     * ceiling somebody invented.
     *
     * @param  array<string, float>  $readings
     */
    private function share(
        PlacementFactor $factor,
        array $readings,
        MetricKind $used,
        MetricKind $total,
    ): ?PlacementComponent {
        $usedValue = $readings[$used->value] ?? null;
        $totalValue = $readings[$total->value] ?? null;

        if ($usedValue === null || $totalValue === null || $totalValue <= 0.0) {
            return null;
        }

        $share = $usedValue / $totalValue;

        return new PlacementComponent($factor, score: $this->clamp(1.0 - $share), measure: $share);
    }

    /**
     * A reading with no ceiling, scored against the busiest candidate.
     *
     * @param  array<string, float>  $readings
     */
    private function relative(
        PlacementFactor $factor,
        array $readings,
        MetricKind $metric,
        ?float $peak,
    ): ?PlacementComponent {
        $value = $readings[$metric->value] ?? null;

        if ($value === null || $peak === null) {
            return null;
        }

        // Everything idle: nothing to choose between, and saying so is better
        // than a division that would make the answer look decisive.
        if ($peak <= 0.0) {
            return new PlacementComponent($factor, score: 1.0, measure: $value);
        }

        return new PlacementComponent($factor, score: $this->clamp(1.0 - ($value / $peak)), measure: $value);
    }

    /**
     * What the graph last said about the node.
     *
     * `unknown` declines: a node nothing has ever checked is not a healthy node
     * and it is not a sick one either, and the mean handles that correctly. So
     * does a word this version does not recognise - two spellings of healthy
     * already exist in this product, and a third one arriving from a module
     * must not read as failing.
     */
    private function health(?string $health): ?PlacementComponent
    {
        $score = match ($health) {
            HealthState::Ok->value, 'healthy' => 1.0,
            HealthState::Degraded->value => 0.25,
            HealthState::Failing->value, 'unreachable' => 0.0,
            default => null,
        };

        return $score === null ? null : new PlacementComponent(PlacementFactor::Health, score: $score);
    }

    /**
     * Whether the node is where the customer is.
     *
     * A node with no region declared declines rather than losing: an
     * installation that never filled the field in would otherwise have every
     * placement decided by a column nobody uses.
     */
    private function region(Server $server, ?string $preferred): ?PlacementComponent
    {
        if ($preferred === null || $server->region === null || $server->region === '') {
            return null;
        }

        return new PlacementComponent(
            PlacementFactor::Region,
            score: strcasecmp($server->region, $preferred) === 0 ? 1.0 : 0.0,
        );
    }

    /**
     * Where the daily series says the node's disk is heading.
     *
     * Disk only, of everything that could be forecast. Memory and CPU pressure
     * are relieved by moving one service; a disk that fills is an outage on
     * every account on the node, and it is the growth an operator cannot fix on
     * the night it matters.
     */
    private function growth(string $nodeId): ?PlacementComponent
    {
        $outlook = $this->forecast->forNode($nodeId, MetricKind::DiskUsed);

        // The forecast declining and the forecast saying "not filling" are
        // different answers, and only the first one is an absence. A node whose
        // disk has been flat for a fortnight has answered the question.
        if ($outlook === null) {
            return null;
        }

        $days = $outlook->isFilling() ? $outlook->daysRemaining() : null;

        if ($days === null) {
            return new PlacementComponent(PlacementFactor::Growth, score: 1.0);
        }

        return new PlacementComponent(
            PlacementFactor::Growth,
            score: $this->clamp($days / self::GROWTH_HORIZON_DAYS),
            measure: (float) $days,
        );
    }

    /**
     * How much of this customer is already here.
     *
     * Not a refusal and not a hard rule: a customer with five services and one
     * eligible node has to land on it. But a customer whose whole estate sits
     * on one box loses all of it at once, and a placement engine that did not
     * even look would be arranging that on purpose.
     *
     * @param  array<string, int>  $affinity
     */
    private function antiAffinity(Server $server, array $affinity): PlacementComponent
    {
        $already = $affinity[$server->id] ?? 0;

        // Three is where it bottoms out. The shape matters more than the
        // number: one service already here should cost something, and the
        // fourth should not cost more than the third.
        return new PlacementComponent(
            PlacementFactor::AntiAffinity,
            score: $this->clamp(1.0 - ($already / 3)),
            measure: (float) $already,
        );
    }

    private function clamp(float $score): float
    {
        return max(0.0, min(1.0, $score));
    }
}
