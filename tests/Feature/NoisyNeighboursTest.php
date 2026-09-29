<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Intelligence\NoisyNeighbours;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Which service on a machine is crowding the others (§21).
 *
 * **A comparison, not a threshold**, so the tests that matter are the ones
 * about what the comparison refuses to say: a machine with two services is not
 * a distribution, a stale reading is not a reading, and a metric core does not
 * recognise as shared is not compared at all.
 *
 * The median rather than the mean is the whole design, and it has a test of
 * its own — one runaway moves a mean far enough to hide itself behind it.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->graph = app(ResourceGraph::class);
    $this->neighbours = app(NoisyNeighbours::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function host(string $key): ResourceNode
{
    return ResourceNode::factory()->keyed($key)->create([
        'kind' => ResourceKind::Server,
        // The factory would build a customer organization with no parent,
        // which the hierarchy refuses. A machine belongs to whoever runs it.
        'organization_id' => app(OrganizationContext::class)->id(),
    ]);
}

/**
 * A service on a machine, with one reading of one metric.
 *
 * Named `tenant` rather than `service`: a test file has no namespace, so every
 * helper in one is global, and two files declaring `service()` is a fatal that
 * only appears when both are in the same process.
 */
function tenant(ResourceNode $host, string $key, ?MetricKind $metric = null, float $value = 0.0): ResourceNode
{
    $node = ResourceNode::factory()->keyed($key)->create([
        'kind' => ResourceKind::Service,
        'organization_id' => $host->organization_id,
    ]);

    app(ResourceGraph::class)->attach(
        container: $host,
        contained: $node,
        relation: Relation::Hosts,
    );

    if ($metric !== null) {
        ResourceMetric::factory()->against($node)->of($metric, $value)->create();
    }

    return $node;
}

it('says nothing at all when no machine has services', function (): void {
    $report = $this->neighbours->on();

    expect($report->measuredAnything())->toBeFalse()
        ->and($report->rows)->toBe([])
        ->and($report->nodesWithoutPerServiceMetrics)->toBe(0);
});

/**
 * The plan says it in as many words: where only per-node metrics exist the
 * answer is "nothing can be said", in words, rather than a comparison of one.
 */
it('separates a machine that reports nothing per service from one with nothing wrong', function (): void {
    $server = host('web-1');

    foreach (['a', 'b', 'c', 'd'] as $key) {
        tenant($server, $key);
    }

    // The machine itself reports. Its services do not.
    ResourceMetric::factory()->against($server)->of(MetricKind::CpuUtilisation, 0.8)->create();

    $report = $this->neighbours->on();

    expect($report->measuredAnything())->toBeFalse()
        ->and($report->nodesWithoutPerServiceMetrics)->toBe(1)
        ->and($report->nodesTooFew)->toBe(0);
});

it('refuses to compare a machine with too few services on it', function (): void {
    $server = host('web-1');

    tenant($server, 'a', MetricKind::CpuUtilisation, 0.01);
    tenant($server, 'b', MetricKind::CpuUtilisation, 0.90);

    $report = $this->neighbours->on();

    // Two readings: the median is the midpoint between them, so one of the two
    // is always "twice the median". That is arithmetic, not a finding.
    expect($report->measuredAnything())->toBeFalse()
        ->and($report->nodesTooFew)->toBe(1)
        ->and($report->rows)->toBe([]);
});

it('names the service that is using several times the median', function (): void {
    $server = host('web-1');

    foreach (['a', 'b', 'c', 'd'] as $key) {
        tenant($server, $key, MetricKind::CpuUtilisation, 0.10);
    }

    tenant($server, 'loud', MetricKind::CpuUtilisation, 0.60);

    $report = $this->neighbours->on();

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0]->serviceLabel)->toBe('loud')
        ->and($report->rows[0]->nodeLabel)->toBe('web-1')
        ->and($report->rows[0]->median)->toBe(0.10)
        // 0.6 / 0.1 is 5.999999999999999 in binary floating point, which is
        // why the screen prints it to one decimal rather than raw.
        ->and($report->rows[0]->times)->toEqualWithDelta(6.0, 0.0001)
        ->and($report->rows[0]->neighbours)->toBe(5);
});

/**
 * The reason the median is not the mean, in one test.
 *
 * Nine services at 1% and one at 91% has a mean of 10% — so against the mean
 * the runaway is nine times over, which is true but only because it dragged
 * the comparison up with it. Add a second runaway and the mean rises enough to
 * hide both. The median does not move at all.
 */
it('is not moved by the runaway it is measuring', function (): void {
    $server = host('db-1');

    foreach (range(1, 8) as $index) {
        tenant($server, 'quiet-'.$index, MetricKind::CpuUtilisation, 0.01);
    }

    tenant($server, 'loud-1', MetricKind::CpuUtilisation, 0.45);
    tenant($server, 'loud-2', MetricKind::CpuUtilisation, 0.45);

    $report = $this->neighbours->on();

    // The mean here is 0.098, and 0.45 is 4.6 times it — under a x5 multiple
    // both runaways would vanish. The median is 0.01 and they are 45 times it.
    $loud = array_map(static fn ($row): string => $row->serviceLabel, $report->rows);

    expect($loud)->toContain('loud-1', 'loud-2')
        ->and($report->rows[0]->median)->toBe(0.01);
});

it('reports a service whose neighbours use none, without inventing a multiple', function (): void {
    $server = host('web-2');

    foreach (['a', 'b', 'c', 'd'] as $key) {
        tenant($server, $key, MetricKind::DiskIops, 0.0);
    }

    tenant($server, 'busy', MetricKind::DiskIops, 40.0);

    $report = $this->neighbours->on();

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0]->serviceLabel)->toBe('busy')
        ->and($report->rows[0]->median)->toBe(0.0)
        // Not infinity, and not a number this platform made up.
        ->and($report->rows[0]->times)->toBeNull();
});

it('skips a reading that has outlived its own freshness', function (): void {
    CarbonImmutable::setTestNow('2026-09-29 12:00:00');

    $server = host('web-3');

    foreach (['a', 'b', 'c', 'd'] as $key) {
        tenant($server, $key, MetricKind::CpuUtilisation, 0.10);
    }

    $stale = tenant($server, 'stale');

    ResourceMetric::factory()
        ->against($stale)
        ->of(MetricKind::CpuUtilisation, 0.95)
        ->sampledAt(CarbonImmutable::now()->subHour(), staleAfter: 300)
        ->create();

    $report = $this->neighbours->on();

    // A service that stopped reporting is a monitoring problem, not a runaway,
    // and its last known figure would be compared against neighbours that have
    // moved on since.
    expect($report->rows)->toBe([]);
});

it('compares only the metrics that are actually shared', function (): void {
    $server = host('web-4');

    foreach (['a', 'b', 'c', 'd'] as $key) {
        tenant($server, $key, MetricKind::ResponseLatency, 10.0);
    }

    tenant($server, 'slow', MetricKind::ResponseLatency, 900.0);

    // Latency is what the *victims* of a noisy neighbour suffer. Comparing it
    // across neighbours would name the wrong service.
    expect($this->neighbours->on()->rows)->toBe([]);
    expect(MetricKind::ResponseLatency->isContended())->toBeFalse();
    expect(MetricKind::DiskIops->isContended())->toBeTrue();
});

it('never compares services across two different machines', function (): void {
    $quiet = host('quiet-box');
    $busy = host('busy-box');

    foreach (['q1', 'q2', 'q3', 'q4'] as $key) {
        tenant($quiet, $key, MetricKind::CpuUtilisation, 0.01);
    }

    foreach (['b1', 'b2', 'b3', 'b4'] as $key) {
        tenant($busy, $key, MetricKind::CpuUtilisation, 0.20);
    }

    // Against every service on the installation, each of the busy machine's
    // four would be twenty times the median. On their own machine they are
    // exactly ordinary, which is the answer that is true.
    expect($this->neighbours->on(2.0)->rows)->toBe([]);
});

it('drives the screen', function (): void {
    $server = host('web-5');

    foreach (['a', 'b', 'c', 'd'] as $key) {
        tenant($server, $key, MetricKind::CpuUtilisation, 0.10);
    }

    tenant($server, 'loud', MetricKind::CpuUtilisation, 0.60);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/noisy-neighbours')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Intelligence/NoisyNeighbours')
            ->where('measured', true)
            ->has('rows', 1)
            ->where('rows.0.serviceLabel', 'loud')
            ->where('rows.0.metricLabel', 'CPU'));
});

it('takes the multiple from the query string and refuses one it does not offer', function (): void {
    $server = host('web-6');

    foreach (['a', 'b', 'c', 'd'] as $key) {
        tenant($server, $key, MetricKind::CpuUtilisation, 0.10);
    }

    tenant($server, 'loud', MetricKind::CpuUtilisation, 0.60);

    // Six times the median: listed at x5, not at x10.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/noisy-neighbours?multiple=5')
        ->assertOk()
        // 5, not 5.0: a whole float is an integer once it has been through
        // JSON, which is also why the select's options are strings.
        ->assertInertia(fn ($page) => $page->has('rows', 1)->where('multiple', 5));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/noisy-neighbours?multiple=10')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows', 0));

    // A number nobody offered falls back to the default rather than being
    // honoured: it reaches a URL, and a screen filtered by ×0.0001 would list
    // every service on the installation as a runaway.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/noisy-neighbours?multiple=0.001')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('multiple', (int) NoisyNeighbours::DefaultMultiple));
});

it('words every sentence that has a number in it on the server', function (): void {
    $server = host('web-7');

    tenant($server, 'only-one', MetricKind::CpuUtilisation, 0.10);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/noisy-neighbours')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('measured', false)
            // "1 machines" is what the browser would have said.
            ->where(
                'unmeasuredDetail',
                'One machine reports per service but carries fewer than 4 of them, '
                .'which is too few for a median to mean anything.',
            ));
});

it('refuses the screen to somebody who may not see telemetry', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/intelligence/noisy-neighbours')
        ->assertForbidden();
});
