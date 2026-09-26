<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\CapacityOutlook;
use App\Domain\Infrastructure\MetricKind;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceMetricDay;
use Carbon\CarbonImmutable;

/**
 * When a resource runs out, if nothing changes.
 *
 * The only capacity question an operator actually asks, and the reason the
 * daily series exists at all: a number that is 71% today and was 64% a
 * fortnight ago is a disk that fills in about six weeks, and six weeks is
 * long enough to order something.
 *
 * **A straight line, and it says so.** Least squares over the daily averages
 * — no seasonality, no smoothing, no confidence interval. A model an operator
 * cannot check in their head is a model they will believe when it is wrong,
 * and the honest answer to "when does this fill" from fourteen points is a
 * direction and a rough date.
 *
 * **It refuses more often than it answers**, on purpose:
 *
 * - fewer than seven days of history is not a trend, it is a week;
 * - a slope at or below zero is not filling, and "never" is the answer;
 * - a metric with no ceiling has nothing to fill — bytes used means nothing
 *   without bytes total, and inventing a ceiling is how a dashboard gets a
 *   red bar nobody can explain.
 *
 * So the ceiling is never invented. A ratio fills at 1.0. Bytes used fill at
 * bytes total — which this platform knows exactly when the adapter reported
 * both, and not at all when it did not, in which case the question is
 * declined rather than answered against a number somebody made up.
 */
final readonly class CapacityForecast
{
    /** Below this, a line through the points is a line through noise. */
    private const int MINIMUM_DAYS = 7;

    /** How far back to look. A quarter is enough for a disk and cheap to read. */
    private const int WINDOW_DAYS = 90;

    /**
     * What this metric is heading towards, or null when the question does not
     * apply.
     */
    public function forNode(string $nodeId, MetricKind $metric, ?CarbonImmutable $now = null): ?CapacityOutlook
    {
        $ceiling = $this->ceilingFor($nodeId, $metric);

        if ($ceiling === null || $ceiling <= 0.0) {
            return null;
        }

        $at = $now ?? CarbonImmutable::now();

        $days = ResourceMetricDay::query()
            ->withoutGlobalScope('organization')
            ->where('resource_node_id', $nodeId)
            ->where('metric', $metric->value)
            ->whereDate('day', '>=', $at->subDays(self::WINDOW_DAYS)->toDateString())
            ->orderBy('day')
            ->get();

        if ($days->count() < self::MINIMUM_DAYS) {
            return null;
        }

        $points = [];

        foreach ($days as $index => $day) {
            $points[] = [(float) $index, $day->average()];
        }

        $slope = $this->slope($points);
        $latest = $days->last();
        $current = $latest instanceof ResourceMetricDay ? $latest->average() : 0.0;

        if ($slope <= 0.0) {
            return new CapacityOutlook(
                current: $current,
                ceiling: $ceiling,
                perDay: $slope,
                fullOn: null,
                days: $days->count(),
            );
        }

        $remaining = $ceiling - $current;

        if ($remaining <= 0.0) {
            return new CapacityOutlook($current, $ceiling, $slope, $at->toImmutable(), $days->count());
        }

        return new CapacityOutlook(
            current: $current,
            ceiling: $ceiling,
            perDay: $slope,
            fullOn: $at->addDays((int) ceil($remaining / $slope)),
            days: $days->count(),
        );
    }

    /**
     * What "full" means for this metric on this resource.
     *
     * A ratio fills at one. Bytes used fill at bytes total, which is a
     * reading the adapter sent rather than a number this class chose — and
     * when it did not send one, there is no honest ceiling and the question
     * is declined.
     */
    private function ceilingFor(string $nodeId, MetricKind $metric): ?float
    {
        $total = match ($metric) {
            MetricKind::CpuUtilisation => null,
            MetricKind::MemoryUsed => MetricKind::MemoryTotal,
            MetricKind::DiskUsed => MetricKind::DiskTotal,
            default => false,
        };

        if ($total === false) {
            return null;
        }

        if ($total === null) {
            return 1.0;
        }

        $value = ResourceMetric::query()
            ->withoutGlobalScope('organization')
            ->where('resource_node_id', $nodeId)
            ->where('metric', $total->value)
            ->value('value');

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Least squares, written out rather than pulled in.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function slope(array $points): float
    {
        $count = count($points);

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXy = 0.0;
        $sumXx = 0.0;

        foreach ($points as [$x, $y]) {
            $sumX += $x;
            $sumY += $y;
            $sumXy += $x * $y;
            $sumXx += $x * $x;
        }

        $denominator = ($count * $sumXx) - ($sumX * $sumX);

        // Every point on the same day: no line to draw, and no trend either.
        return $denominator === 0.0 ? 0.0 : (($count * $sumXy) - ($sumX * $sumY)) / $denominator;
    }
}
