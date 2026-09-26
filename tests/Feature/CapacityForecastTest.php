<?php

declare(strict_types=1);

use App\Application\Infrastructure\CapacityForecast;
use App\Application\Infrastructure\RecordSamples;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricSample;
use App\Domain\Infrastructure\MetricUnit;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Infrastructure\SampleBatch;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceMetricDay;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceMetricDayFactory;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * When a resource runs out, if nothing changes.
 *
 * Telemetry keeps the present and never the series, so the daily point is the
 * one exception (`advanced-operations-plan.md` §14) and the whole reason it
 * exists is this question. What these assert is mostly the refusals: a
 * forecast that answers when it should not is worse than one that declines,
 * because a date in a meeting is a purchase order.
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
        'web-1',
        'Web one',
    );

    $this->series = function (array $values, MetricKind $metric = MetricKind::CpuUtilisation): void {
        foreach (array_values($values) as $index => $value) {
            ResourceMetricDayFactory::new()
                ->forNode($this->node)
                ->on(CarbonImmutable::now()->subDays(count($values) - $index)->toDateString(), $value)
                ->create(['metric' => $metric->value, 'unit' => MetricUnit::Ratio->value]);
        }
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('says when a filling resource reaches its ceiling', function (): void {
    // Ten days, one point a day, climbing two points of a percent a day.
    ($this->series)([0.60, 0.62, 0.64, 0.66, 0.68, 0.70, 0.72, 0.74, 0.76, 0.78]);

    $outlook = app(CapacityForecast::class)->forNode($this->node->id, MetricKind::CpuUtilisation);

    expect($outlook)->not->toBeNull()
        ->and($outlook->isFilling())->toBeTrue()
        ->and($outlook->days)->toBe(10)
        // 0.22 to go at 0.02 a day is eleven days, give or take the rounding.
        ->and($outlook->daysRemaining())->toBeGreaterThan(9)
        ->and($outlook->daysRemaining())->toBeLessThan(13);
});

/**
 * "This is not the disk to worry about" is what an operator reading a list of
 * forty resources needs most.
 */
it('answers that a flat resource is not filling at all', function (): void {
    ($this->series)([0.40, 0.41, 0.40, 0.39, 0.40, 0.41, 0.40, 0.39]);

    $outlook = app(CapacityForecast::class)->forNode($this->node->id, MetricKind::CpuUtilisation);

    expect($outlook)->not->toBeNull()
        ->and($outlook->isFilling())->toBeFalse()
        ->and($outlook->daysRemaining())->toBeNull();
});

it('declines to draw a line through a week', function (): void {
    ($this->series)([0.50, 0.55, 0.60]);

    expect(app(CapacityForecast::class)->forNode($this->node->id, MetricKind::CpuUtilisation))
        ->toBeNull();
});

/**
 * Bytes used means nothing without bytes total, and inventing a ceiling is
 * how a dashboard grows a red bar nobody can explain.
 */
it('declines a metric whose ceiling nothing reported', function (): void {
    ($this->series)(
        [100.0, 110.0, 120.0, 130.0, 140.0, 150.0, 160.0, 170.0],
        MetricKind::DiskUsed,
    );

    expect(app(CapacityForecast::class)->forNode($this->node->id, MetricKind::DiskUsed))
        ->toBeNull();
});

it('uses the total the adapter reported as the ceiling', function (): void {
    ($this->series)(
        [100.0, 110.0, 120.0, 130.0, 140.0, 150.0, 160.0, 170.0],
        MetricKind::DiskUsed,
    );

    app(RecordSamples::class)->handle($this->provider->id, 'test', new SampleBatch(samples: [
        new MetricSample(
            target: 'web-1',
            metric: MetricKind::DiskTotal,
            value: 500.0,
            sampledAt: CarbonImmutable::now(),
        ),
    ]));

    $outlook = app(CapacityForecast::class)->forNode($this->node->id, MetricKind::DiskUsed);

    expect($outlook)->not->toBeNull()
        ->and($outlook->ceiling)->toBe(500.0)
        ->and($outlook->isFilling())->toBeTrue();
});

/**
 * The collector folds each reading into its day as it arrives. A nightly job
 * reading the present value would find one number and call it an average.
 */
it('accumulates a day as the readings arrive', function (): void {
    $at = CarbonImmutable::now();

    foreach ([0.2, 0.8, 0.5] as $value) {
        app(RecordSamples::class)->handle($this->provider->id, 'test', new SampleBatch(samples: [
            new MetricSample(
                target: 'web-1',
                metric: MetricKind::CpuUtilisation,
                value: $value,
                sampledAt: $at,
            ),
        ]));
    }

    $day = ResourceMetricDay::query()
        ->where('resource_node_id', $this->node->id)
        ->where('metric', MetricKind::CpuUtilisation->value)
        ->sole();

    expect($day->samples)->toBe(3)
        ->and($day->minimum)->toBe(0.2)
        ->and($day->maximum)->toBe(0.8)
        ->and($day->last)->toBe(0.5)
        ->and(round($day->average(), 4))->toBe(0.5);
});

/**
 * The day comes from the reading's own timestamp: a batch that arrives at
 * 00:00:02 carrying a 23:59 sample belongs to yesterday.
 */
it('files a reading under the day it was taken', function (): void {
    $yesterday = CarbonImmutable::now()->subDay()->setTime(23, 59);

    app(RecordSamples::class)->handle($this->provider->id, 'test', new SampleBatch(samples: [
        new MetricSample(
            target: 'web-1',
            metric: MetricKind::CpuUtilisation,
            value: 0.3,
            sampledAt: $yesterday,
        ),
    ]));

    expect(ResourceMetricDay::query()->where('resource_node_id', $this->node->id)->sole()->day->toDateString())
        ->toBe($yesterday->toDateString());
});
