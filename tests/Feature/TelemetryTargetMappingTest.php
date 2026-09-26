<?php

declare(strict_types=1);

use App\Application\Infrastructure\RecordSamples;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricSample;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Infrastructure\SampleBatch;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * What an adapter calls a machine, and what this platform calls it.
 *
 * Phase A left this open in as many words: a server's node key is its ULID,
 * because a hostname is not unique and two machines sharing one would
 * silently become a single node — "how an adapter's own inventory finds a
 * node key is Phase B's problem, and the answer is a mapping rather than a
 * guess".
 *
 * Without the mapping, every reading from a real monitoring system lands in
 * `unplaced` while the installation is configured perfectly, and the
 * Telemetry screen stays empty for a reason nobody can see.
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
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'Web one',
        attributes: ['hostname' => 'web-1.dc2', 'region' => 'eu-west'],
    );

    $this->send = fn (string $target): array => app(RecordSamples::class)->handle(
        $this->provider->id,
        'prometheus',
        new SampleBatch(samples: [
            new MetricSample(
                target: $target,
                metric: MetricKind::CpuUtilisation,
                value: 0.42,
                sampledAt: CarbonImmutable::now(),
            ),
        ]),
    )->unplaced;
});

it('still matches the node key an adapter was told', function (): void {
    expect(($this->send)('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->toBe([]);

    expect(ResourceMetric::query()->where('resource_node_id', $this->node->id)->count())->toBe(1);
});

it('matches the hostname an operator typed on the server', function (): void {
    expect(($this->send)('web-1.dc2'))->toBe([]);

    expect(ResourceMetric::query()->where('resource_node_id', $this->node->id)->count())->toBe(1);
});

/**
 * An exporter's address is a host and a port, and the machine is the host.
 */
it('strips the port an exporter answers on', function (): void {
    expect(($this->send)('web-1.dc2:9100'))->toBe([]);

    expect(ResourceMetric::query()->where('resource_node_id', $this->node->id)->count())->toBe(1);
});

it('reads an IPv6 address with a port without cutting it in half', function (): void {
    app(ResourceGraph::class)->upsertNode(
        $this->provider->id,
        ResourceKind::Server,
        '01ARZ3NDEKTSV4RRFFQ69G5FB0',
        'Six',
        attributes: ['hostname' => '2001:db8::1'],
    );

    expect(($this->send)('[2001:db8::1]:9100'))->toBe([]);
});

it('does not care about the case somebody typed', function (): void {
    expect(($this->send)('WEB-1.DC2'))->toBe([]);
});

/**
 * Two machines claiming one hostname produce no match at all: a reading
 * attached to the wrong machine is worse than one nobody placed, because the
 * first is acted on.
 */
it('refuses an ambiguous hostname rather than choosing', function (): void {
    app(ResourceGraph::class)->upsertNode(
        $this->provider->id,
        ResourceKind::Server,
        '01ARZ3NDEKTSV4RRFFQ69G5FB1',
        'Web one, again',
        attributes: ['hostname' => 'web-1.dc2'],
    );

    expect(($this->send)('web-1.dc2'))->toBe(['web-1.dc2' => 1]);

    expect(ResourceMetric::query()->count())->toBe(0);
});

/**
 * The boundary still decides. A reseller's Prometheus naming a hostname the
 * provider happens to use must not write onto the provider's node.
 */
it('never matches a hostname in another organization', function (): void {
    $reseller = Organization::factory()->reseller($this->provider)->create();

    $unplaced = app(RecordSamples::class)->handle(
        $reseller->id,
        'prometheus',
        new SampleBatch(samples: [
            new MetricSample(
                target: 'web-1.dc2',
                metric: MetricKind::CpuUtilisation,
                value: 0.9,
                sampledAt: CarbonImmutable::now(),
            ),
        ]),
    )->unplaced;

    expect($unplaced)->toBe(['web-1.dc2' => 1])
        ->and(ResourceMetric::query()->count())->toBe(0);
});
