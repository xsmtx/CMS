<?php

declare(strict_types=1);

use App\Application\Infrastructure\RecordSamples;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Infrastructure\SampleNormalizer;
use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricUnit;
use App\Domain\Infrastructure\RawSample;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Infrastructure\SampleBatch;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * Normalization: one vocabulary, one unit per dimension, and a refusal rather
 * than a guess.
 *
 * The whole value of a telemetry layer is that `node_cpu_utilisation`,
 * `system.cpu.util` and `cpu_percent` become one question. The tests that matter
 * most here are the refusals, because a wrong conversion looks plausible on a
 * screen forever.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->node = app(ResourceGraph::class)->upsertNode(
        $this->provider->id,
        ResourceKind::Server,
        'node-1',
        'Node 1',
    );

    $this->second = app(ResourceGraph::class)->upsertNode(
        $this->provider->id,
        ResourceKind::Server,
        'node-2',
        'Node 2',
    );
});

it('reads three vendors spelling of one metric as the same question', function (): void {
    $batch = app(SampleNormalizer::class)->normalize([
        new RawSample('node-1', 'node_cpu_utilisation', 0.4),
        new RawSample('node-2', 'system.cpu.util', 40, 'percent'),
        new RawSample('node-1', 'cpu_percent', 40.0, 'percent'),
    ]);

    expect($batch->samples)->toHaveCount(3);

    foreach ($batch->samples as $sample) {
        expect($sample->metric)->toBe(MetricKind::CpuUtilisation)
            ->and($sample->unit())->toBe(MetricUnit::Ratio)
            ->and($sample->value)->toBeGreaterThan(0.39)
            ->and($sample->value)->toBeLessThan(0.41);
    }
});

it('converts data and throughput into one unit each', function (): void {
    $batch = app(SampleNormalizer::class)->normalize([
        new RawSample('node-1', 'memory_used', 8, 'gigabytes'),
        new RawSample('node-1', 'rx_bps', 100, 'megabits_per_second'),
        new RawSample('node-1', 'uptime', 2, 'hours'),
    ]);

    $values = [];

    foreach ($batch->samples as $sample) {
        $values[$sample->metric->value] = $sample->value;
    }

    expect($values['memory.used'])->toBe(8_000_000_000.0)
        ->and($values['network.in'])->toBe(100_000_000.0)
        ->and($values['uptime'])->toBe(7_200.0);
});

it('refuses a metric it has no name for, and counts it', function (): void {
    $batch = app(SampleNormalizer::class)->normalize([
        new RawSample('node-1', 'widgets_frobnicated', 12),
        new RawSample('node-1', 'widgets_frobnicated', 13),
    ]);

    // Dropped rather than stored under its own name: a table that accepts any
    // metric name is a time-series database nobody sized.
    expect($batch->samples)->toBeEmpty()
        ->and($batch->unmapped['widgets_frobnicated'])->toBe(2)
        ->and($batch->refusedCount())->toBe(2);
});

it('refuses a unit from the wrong dimension rather than converting it', function (): void {
    $batch = app(SampleNormalizer::class)->normalize([
        // Bytes offered for a CPU ratio is not a conversion, it is a mistake —
        // and storing it would be wrong by a factor nobody can see.
        new RawSample('node-1', 'cpu_percent', 4096, 'bytes'),
        new RawSample('node-1', 'memory_used', 8, 'furlongs'),
    ]);

    expect($batch->samples)->toBeEmpty()
        ->and($batch->mismatched)->toHaveCount(2);
});

it('takes the metric own unit when the source names none', function (): void {
    $batch = app(SampleNormalizer::class)->normalize([
        new RawSample('node-1', 'accounts', 118),
    ]);

    expect($batch->samples[0]->value)->toBe(118.0)
        ->and($batch->samples[0]->unit())->toBe(MetricUnit::Count);
});

it('writes one row per node and metric, replacing rather than appending', function (): void {
    $record = app(RecordSamples::class);

    $record->handle($this->provider->id, 'probe', new SampleBatch([
        new RawSample('node-1', 'cpu_percent', 40, 'percent'),
    ]));

    $record->handle($this->provider->id, 'probe', new SampleBatch([
        new RawSample('node-1', 'cpu_percent', 60, 'percent'),
    ]));

    $rows = ResourceMetric::query()->where('resource_node_id', $this->node->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->value)->toBe(0.6)
        ->and($rows[0]->metric)->toBe(MetricKind::CpuUtilisation);
});

it('does not invent a node for a reading about something unknown', function (): void {
    $outcome = app(RecordSamples::class)->handle($this->provider->id, 'probe', new SampleBatch([
        new RawSample('a-machine-nobody-here-runs', 'cpu_percent', 40, 'percent'),
    ]));

    expect($outcome->recorded)->toBe(0)
        ->and($outcome->unplaced)->toHaveKey('a-machine-nobody-here-runs')
        ->and(ResourceNode::query()->count())->toBe(2);
});

it('keeps what the adapter could not answer for separate from what it could not name', function (): void {
    $outcome = app(RecordSamples::class)->handle($this->provider->id, 'probe', new SampleBatch(
        samples: [new RawSample('node-1', 'widgets', 1)],
        unknownTargets: ['node-2'],
    ));

    // "We do not know about their thing" and "they do not know about ours" are
    // different answers and need different people to do different things.
    expect($outcome->unmapped)->toHaveKey('widgets')
        ->and($outcome->unknownTargets)->toBe(['node-2'])
        ->and($outcome->isClean())->toBeFalse();
});

it('calls a node healthy while a reading is fresh and degraded once every one is stale', function (): void {
    $record = app(RecordSamples::class);

    $record->handle($this->provider->id, 'probe', new SampleBatch([
        new RawSample(
            'node-1',
            'cpu_percent',
            40,
            'percent',
            CarbonImmutable::now(),
            staleAfterSeconds: 300,
        ),
    ]));

    expect($this->node->fresh()->health)->toBe(HealthState::Ok->value);

    $record->handle($this->provider->id, 'probe', new SampleBatch([
        new RawSample(
            'node-1',
            'cpu_percent',
            40,
            'percent',
            CarbonImmutable::now()->subHour(),
            staleAfterSeconds: 300,
        ),
    ]));

    expect($this->node->fresh()->health)->toBe(HealthState::Degraded->value);
});

it('leaves a node nobody reports on as unknown', function (): void {
    app(RecordSamples::class)->handle($this->provider->id, 'probe', new SampleBatch([
        new RawSample('node-1', 'cpu_percent', 40, 'percent'),
    ]));

    // The count the Telemetry screen exists to show: "nothing is watching this"
    // and "this is fine" are different answers.
    expect($this->second->fresh()->health)->toBe(ResourceNode::HealthUnknown);
});

it('writes no translated sentence into the health column', function (): void {
    app(RecordSamples::class)->handle($this->provider->id, 'probe', new SampleBatch([
        new RawSample(
            'node-1',
            'cpu_percent',
            40,
            'percent',
            CarbonImmutable::now()->subDay(),
            staleAfterSeconds: 60,
        ),
    ]));

    // A message stored in the language of whichever run happened to write it is
    // a message the next operator cannot read. The screen has `sampled_at`.
    expect($this->node->fresh()->health_message)->toBeNull();
});
