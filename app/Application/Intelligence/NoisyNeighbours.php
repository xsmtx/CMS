<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\ResourceKind;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use Carbon\CarbonImmutable;

/**
 * Which service on a machine is using more than its neighbours (§21).
 *
 * **A comparison, not a threshold.** A service using 40% of a machine's CPU is
 * fine on a machine with two services and a problem on one with forty, so
 * there is no number in this class that says "too much". The question is about
 * one node's own distribution, and the answer scales itself.
 *
 * **Against the median, never the mean.** The mean of thirty-nine idle
 * accounts and one runaway is a mean the runaway moved — it rises with the
 * thing it is supposed to measure against, and on a machine with one very
 * noisy tenant it rises far enough that the tenant looks ordinary. The median
 * does not move at all.
 *
 * **It declines more often than it answers**, like `CapacityForecast`. Two
 * services on a node is not a distribution: the median of two readings is the
 * midpoint between them, so each is always compared against the other and one
 * of the two is always "twice the median". Below `MinimumNeighbours` the
 * honest answer is that nothing can be said, and the report carries the count
 * so the screen can say it.
 *
 * **A stale reading is skipped rather than compared.** A service that stopped
 * reporting is a monitoring problem, and its last known figure would be
 * compared against neighbours that have moved on since.
 *
 * There is **no table** behind this and there should not be: it is a question
 * about rows that already exist, asked when somebody opens the screen. A
 * stored copy would disagree with its source by the afternoon.
 */
final readonly class NoisyNeighbours
{
    /**
     * The default the screen opens on, and a convention rather than a rule
     * this product invented — which is why it is on the query string, visible
     * and adjustable, instead of hidden in a setting somebody has to find.
     */
    public const float DefaultMultiple = 3.0;

    /**
     * Fewer than this on a machine and the comparison means nothing.
     *
     * Four, because with three the median *is* the middle service, so the
     * comparison is against a single neighbour wearing a statistic's name.
     */
    public const int MinimumNeighbours = 4;

    public function on(float $multiple = self::DefaultMultiple): NeighbourReport
    {
        $now = CarbonImmutable::now();

        $services = $this->servicesByHost();

        if ($services === []) {
            return new NeighbourReport([], 0, 0, 0, $multiple, self::MinimumNeighbours);
        }

        $readings = $this->contendedReadings(
            array_merge(...array_values(array_map(array_keys(...), $services))),
            $now,
        );

        $nodes = $this->labels($services);

        $rows = [];
        $compared = 0;
        $unmeasured = 0;
        $tooFew = 0;

        foreach ($services as $hostId => $serviceIds) {
            $byMetric = $this->group(array_keys($serviceIds), $readings);

            if ($byMetric === []) {
                $unmeasured++;

                continue;
            }

            $measured = false;

            foreach ($byMetric as $metric => $values) {
                if (count($values) < self::MinimumNeighbours) {
                    continue;
                }

                $measured = true;

                foreach ($this->noisy($values, $multiple) as $serviceId => $comparison) {
                    [$value, $median, $times] = $comparison;

                    $host = $nodes[$hostId] ?? null;
                    $service = $nodes[$serviceId] ?? null;

                    if ($host === null || $service === null) {
                        continue;
                    }

                    $kind = MetricKind::from((string) $metric);

                    $rows[] = new NoisyRow(
                        nodeKey: $host->node_key,
                        nodeLabel: $host->label ?? $host->node_key,
                        serviceKey: $service->node_key,
                        serviceLabel: $service->label ?? $service->node_key,
                        serviceId: $service->subject_id,
                        metric: $kind,
                        unit: $kind->unit(),
                        value: $value,
                        median: $median,
                        times: $times,
                        neighbours: count($values),
                    );
                }
            }

            $measured ? $compared++ : $tooFew++;
        }

        /*
         * Worst first, and a row with no multiple (a median of nought) sorts
         * above one with a multiple — it is the starker finding, since every
         * neighbour is using none of the thing at all.
         */
        usort($rows, static function (NoisyRow $a, NoisyRow $b): int {
            $order = ($b->times ?? INF) <=> ($a->times ?? INF);

            return $order !== 0 ? $order : $b->value <=> $a->value;
        });

        return new NeighbourReport(
            rows: $rows,
            nodesCompared: $compared,
            nodesWithoutPerServiceMetrics: $unmeasured,
            nodesTooFew: $tooFew,
            multiple: $multiple,
            minimumNeighbours: self::MinimumNeighbours,
        );
    }

    /**
     * Every service node, grouped by the machine holding it.
     *
     * One query. The edge belongs to the container's organization (ADR 0043),
     * which is the seller's, so the ordinary boundary is the right one here
     * and no escape hatch is needed.
     *
     * @return array<string, array<string, true>>
     */
    private function servicesByHost(): array
    {
        $edges = ResourceEdge::query()
            ->whereNull('ended_at')
            ->whereIn('relation', [Relation::Contains->value, Relation::Hosts->value])
            ->whereHas('from', fn ($query) => $query->where('kind', ResourceKind::Server)->whereNull('retired_at'))
            ->whereHas('to', fn ($query) => $query->where('kind', ResourceKind::Service)->whereNull('retired_at'))
            ->get(['from_node_id', 'to_node_id']);

        $byHost = [];

        foreach ($edges as $edge) {
            // Keyed rather than appended: two open edges between one pair is
            // a duplicate a discovery run can leave behind, and counting the
            // service twice would move the median.
            $byHost[$edge->from_node_id][$edge->to_node_id] = true;
        }

        return $byHost;
    }

    /**
     * The fresh, contended readings for a set of service nodes.
     *
     * @param  list<string>  $nodeIds
     * @return array<string, array<string, float>>
     */
    private function contendedReadings(array $nodeIds, CarbonImmutable $now): array
    {
        $contended = array_values(array_filter(
            MetricKind::cases(),
            static fn (MetricKind $metric): bool => $metric->isContended(),
        ));

        $rows = ResourceMetric::query()
            ->whereIn('resource_node_id', array_values(array_unique($nodeIds)))
            ->whereIn('metric', array_map(static fn (MetricKind $m): string => $m->value, $contended))
            ->get();

        $readings = [];

        foreach ($rows as $row) {
            if ($row->metric === null || $row->isStale($now)) {
                continue;
            }

            $readings[$row->resource_node_id][$row->metric->value] = $row->value;
        }

        return $readings;
    }

    /**
     * The readings on one machine, by metric.
     *
     * @param  list<string>  $serviceIds
     * @param  array<string, array<string, float>>  $readings
     * @return array<string, array<string, float>>
     */
    private function group(array $serviceIds, array $readings): array
    {
        $byMetric = [];

        foreach ($serviceIds as $serviceId) {
            foreach ($readings[$serviceId] ?? [] as $metric => $value) {
                $byMetric[$metric][$serviceId] = $value;
            }
        }

        return $byMetric;
    }

    /**
     * Which of one machine's readings for one metric stand out.
     *
     * @param  array<string, float>  $values
     * @return array<string, array{float, float, ?float}>
     */
    private function noisy(array $values, float $multiple): array
    {
        $median = $this->median(array_values($values));

        $noisy = [];

        foreach ($values as $serviceId => $value) {
            if ($median > 0.0) {
                if ($value >= $median * $multiple) {
                    $noisy[$serviceId] = [$value, $median, $value / $median];
                }

                continue;
            }

            /*
             * A median of nought: every neighbour is using none of this, so
             * anything measurable stands out and no multiple of it exists.
             * Reported with a null multiple rather than skipped — thirty-nine
             * idle sites and one busy one is the shape this feature was built
             * for, and it is the shape that produces a zero median.
             */
            if ($value > 0.0) {
                $noisy[$serviceId] = [$value, 0.0, null];
            }
        }

        return $noisy;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);

        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Every node the rows will name, in one query.
     *
     * @param  array<string, array<string, true>>  $services
     * @return array<string, ResourceNode>
     */
    private function labels(array $services): array
    {
        $ids = array_keys($services);

        foreach ($services as $serviceIds) {
            $ids = array_merge($ids, array_keys($serviceIds));
        }

        return ResourceNode::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->keyBy('id')
            ->all();
    }
}
