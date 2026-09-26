<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\MetricSample;
use App\Domain\Infrastructure\RawSample;
use App\Domain\Infrastructure\SampleBatch;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceMetricDay;
use App\Infrastructure\Resources\Models\ResourceNode;
use Carbon\CarbonImmutable;

/**
 * Writes readings against nodes, and says what it could not place.
 *
 * The only path from an adapter to `resource_metrics`. Three rules live here.
 *
 * **A sample about something this platform does not know is not stored.** An
 * adapter reporting a host with no node has either discovered something (Phase B's
 * job, with an operator's decision behind it) or is reporting about a machine this
 * installation does not run. Creating a node for it here would let a misconfigured
 * poller invent inventory.
 *
 * **One row per node and metric.** The row is replaced, not appended to; the
 * history lives in the monitoring system that already has it (§14). What this
 * keeps is the present, and the present is bounded by node count.
 *
 * **Health here means "is anything telling us about this", not "is it well".**
 * There are no thresholds in this phase and a screen that invented one would be
 * inventing an alert. So a node with fresh readings is `ok`, a node whose every
 * reading has outlived its declared freshness is `degraded`, and a node nobody
 * reports on at all stays `unknown` — which is the count the Telemetry screen
 * exists to show.
 */
final readonly class RecordSamples
{
    public function __construct(private SampleNormalizer $normalizer) {}

    public function handle(
        string $organizationId,
        string $source,
        SampleBatch $batch,
        ?CarbonImmutable $now = null,
    ): SampleOutcome {
        $at = $now ?? CarbonImmutable::now();

        /** @var list<RawSample> $raw */
        $raw = array_values(array_filter(
            $batch->samples,
            static fn (RawSample|MetricSample $sample): bool => $sample instanceof RawSample,
        ));

        $normalized = $this->normalizer->normalize($raw, $at);

        /** @var list<MetricSample> $ready */
        $ready = [
            ...$normalized->samples,
            // An adapter that has already done the naming itself is allowed to;
            // the example probe reads this platform's own numbers and knows
            // exactly what they are called.
            ...array_values(array_filter(
                $batch->samples,
                static fn (RawSample|MetricSample $sample): bool => $sample instanceof MetricSample,
            )),
        ];

        $nodes = $this->nodesFor($organizationId, $ready);

        $recorded = 0;
        $unplaced = [];
        $touched = [];

        foreach ($ready as $sample) {
            $node = $nodes[$sample->target] ?? null;

            if (! $node instanceof ResourceNode) {
                $unplaced[$sample->target] = ($unplaced[$sample->target] ?? 0) + 1;

                continue;
            }

            $this->write($node, $sample, $source);
            $this->accumulate($node, $sample);

            $recorded++;
            $touched[$node->id] = $node;
        }

        foreach ($touched as $node) {
            $this->refreshHealth($node, $at);
        }

        return new SampleOutcome(
            recorded: $recorded,
            unmapped: $normalized->unmapped,
            mismatched: $normalized->mismatched,
            unplaced: $unplaced,
            unknownTargets: $batch->unknownTargets,
        );
    }

    /**
     * @param  list<MetricSample>  $samples
     * @return array<string, ResourceNode>
     */
    private function nodesFor(string $organizationId, array $samples): array
    {
        $keys = array_values(array_unique(array_map(
            static fn (MetricSample $sample): string => $sample->target,
            $samples,
        )));

        if ($keys === []) {
            return [];
        }

        // Unscoped and filtered by the organization explicitly: a collector runs
        // on a schedule with no actor, so there is no boundary to inherit, and
        // the organization it was told about is the only one it may write to.
        $nodes = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereNull('retired_at')
            ->whereIn('node_key', $keys)
            ->get();

        $indexed = [];

        foreach ($nodes as $node) {
            $indexed[$node->node_key] = $node;
        }

        return $indexed;
    }

    private function write(ResourceNode $node, MetricSample $sample, string $source): void
    {
        ResourceMetric::query()->updateOrCreate(
            [
                'resource_node_id' => $node->id,
                'metric' => $sample->metric->value,
            ],
            [
                'organization_id' => $node->organization_id,
                'unit' => $sample->unit()->value,
                'value' => $sample->value,
                'sampled_at' => $sample->sampledAt,
                'stale_after_seconds' => $sample->staleAfterSeconds,
                'source' => $source,
            ],
        );
    }

    /**
     * Fold one reading into the day it belongs to.
     *
     * Telemetry keeps the present and never the series — Prometheus and
     * Zabbix own the history — and this is the one exception the plan names
     * (§14): a daily point per node and metric, which is 365 rows a year for
     * a thing and the only shape a capacity answer can be built from.
     *
     * Accumulated here rather than rolled up at midnight because a nightly
     * job reading `resource_metrics` would find one value, the last one
     * written, and call it a day's average. `max` is the peak that was
     * actually seen, which is the number a capacity question is about.
     *
     * The day comes from the reading's own timestamp, not from the clock: a
     * batch that arrives at 00:00:02 carrying a 23:59 sample belongs to
     * yesterday.
     */
    private function accumulate(ResourceNode $node, MetricSample $sample): void
    {
        $day = $sample->sampledAt->toDateString();

        $existing = ResourceMetricDay::query()
            ->withoutGlobalScope('organization')
            ->where('resource_node_id', $node->id)
            ->where('metric', $sample->metric->value)
            ->whereDate('day', $day)
            ->first();

        if ($existing instanceof ResourceMetricDay) {
            $existing->forceFill([
                'samples' => $existing->samples + 1,
                'minimum' => min($existing->minimum, $sample->value),
                'maximum' => max($existing->maximum, $sample->value),
                'sum' => $existing->sum + $sample->value,
                'last' => $sample->value,
                'unit' => $sample->unit()->value,
            ])->save();

            return;
        }

        ResourceMetricDay::query()->create([
            'organization_id' => $node->organization_id,
            'resource_node_id' => $node->id,
            'metric' => $sample->metric->value,
            'unit' => $sample->unit()->value,
            'day' => $day,
            'samples' => 1,
            'minimum' => $sample->value,
            'maximum' => $sample->value,
            'sum' => $sample->value,
            'last' => $sample->value,
        ]);
    }

    private function refreshHealth(ResourceNode $node, CarbonImmutable $at): void
    {
        $metrics = ResourceMetric::query()
            ->withoutGlobalScope('organization')
            ->where('resource_node_id', $node->id)
            ->get();

        if ($metrics->isEmpty()) {
            return;
        }

        $stale = $metrics->filter(static fn (ResourceMetric $metric): bool => $metric->isStale($at));
        $everythingIsStale = $stale->count() === $metrics->count();

        /*
         * No sentence is written into the column, deliberately.
         *
         * A message stored in the language of whoever's run happened to write it
         * is a message the next operator cannot read, and this one would be
         * written by a scheduler with no locale at all. The screen has
         * `sampled_at` for every reading and says "nothing since 14:02" in the
         * reader's own language. `health_message` stays for what an *adapter*
         * says, which is already its own words rather than ours.
         */
        $node->health = $everythingIsStale ? HealthState::Degraded->value : HealthState::Ok->value;
        $node->health_message = null;
        $node->save();
    }
}
