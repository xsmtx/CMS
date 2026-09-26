<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\CapacityOutlook;
use App\Domain\Infrastructure\MetricKind;
use App\Infrastructure\Resources\Models\ResourceMetricDay;
use App\Infrastructure\Resources\Models\ResourceNode;
use Carbon\CarbonImmutable;

/**
 * What is running out, soonest first.
 *
 * `CapacityForecast` answers for one resource and one metric; this is the
 * question a screen asks — *which* of four hundred resources is worth looking
 * at this week.
 *
 * **It starts from the daily table rather than from the resources.** Asking
 * every node about every metric would be four hundred forecasts to find the
 * three that matter; asking the daily rows which pairs even have a week of
 * history behind them is one grouped query, and everything after it is a
 * forecast worth computing.
 *
 * **Only what is filling comes back.** A flat line is an answer
 * (`CapacityForecast` returns it) but it is not a row on this list: a screen
 * of forty resources that says "not filling" forty times is a screen nobody
 * reads to the bottom.
 */
final readonly class CapacityOutlooks
{
    /**
     * The metrics a ceiling can be found for. Anything else has nothing to
     * fill, and inventing a ceiling is what this whole area refuses to do.
     */
    private const array FORECASTABLE = [
        MetricKind::CpuUtilisation,
        MetricKind::MemoryUsed,
        MetricKind::DiskUsed,
    ];

    /** A week of points, which is `CapacityForecast`'s own floor. */
    private const int MINIMUM_DAYS = 7;

    private const int WINDOW_DAYS = 90;

    public function __construct(private CapacityForecast $forecast) {}

    /**
     * @return list<array{node: ResourceNode, metric: MetricKind, outlook: CapacityOutlook}>
     */
    public function soonest(string $organizationId, int $limit = 25, ?CarbonImmutable $now = null): array
    {
        $at = $now ?? CarbonImmutable::now();

        $pairs = ResourceMetricDay::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereIn('metric', array_map(
                static fn (MetricKind $metric): string => $metric->value,
                self::FORECASTABLE,
            ))
            ->whereDate('day', '>=', $at->subDays(self::WINDOW_DAYS)->toDateString())
            ->groupBy('resource_node_id', 'metric')
            ->havingRaw('count(*) >= ?', [self::MINIMUM_DAYS])
            ->get(['resource_node_id', 'metric']);

        if ($pairs->isEmpty()) {
            return [];
        }

        $nodes = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->whereIn('id', $pairs->pluck('resource_node_id')->unique()->all())
            ->whereNull('retired_at')
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($pairs as $pair) {
            $node = $nodes->get($pair->resource_node_id);
            $metric = MetricKind::tryFrom($pair->metric);

            if (! $node instanceof ResourceNode || $metric === null) {
                continue;
            }

            $outlook = $this->forecast->forNode($node->id, $metric, $at);

            if ($outlook === null || ! $outlook->isFilling()) {
                continue;
            }

            $rows[] = ['node' => $node, 'metric' => $metric, 'outlook' => $outlook];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($a['outlook']->daysRemaining() ?? PHP_INT_MAX)
                <=> ($b['outlook']->daysRemaining() ?? PHP_INT_MAX),
        );

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Every capacity question that can be answered about one resource.
     *
     * **Including the flat ones**, which is the difference from `soonest()`. On a
     * list of four hundred resources "not filling" forty times is noise; on the
     * one resource somebody has opened, "this disk has been flat for three
     * months" is the answer they came for, and leaving it out would read as the
     * platform having nothing to say.
     *
     * @return list<array{metric: MetricKind, outlook: CapacityOutlook}>
     */
    public function forNode(string $nodeId, ?CarbonImmutable $now = null): array
    {
        $rows = [];

        foreach (self::FORECASTABLE as $metric) {
            $outlook = $this->forecast->forNode($nodeId, $metric, $now);

            if ($outlook === null) {
                continue;
            }

            $rows[] = ['metric' => $metric, 'outlook' => $outlook];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($a['outlook']->daysRemaining() ?? PHP_INT_MAX)
                <=> ($b['outlook']->daysRemaining() ?? PHP_INT_MAX),
        );

        return $rows;
    }
}
