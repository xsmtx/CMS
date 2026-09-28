<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\Storage\StorageHealth;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Storage discovery, driven through the real Ceph package over faked HTTP
 * (`phase-f-plan.md` §5 step 2).
 *
 * Against the package rather than a mock, for the reason
 * `TopologyDiscoveryTest` gives: `ActiveModules` is final on purpose, and a
 * fake of it would be a fake of the thing under test.
 *
 * What is pinned is the decisions, not the plumbing: a pool's total is
 * derived because Ceph does not report one, a pool's health comes from its
 * own placement groups rather than from the cluster's, an image inherits no
 * health it was not told about, and capacity is telemetry rather than an
 * attribute.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();
});

/**
 * What the fake Ceph is currently reporting.
 *
 * A static rather than a second `Http::fake()` call: faking twice adds a stub
 * rather than replacing the first, so a test that "changed the answer" would
 * change nothing.
 */
final class CephState
{
    /** @var list<array<string, mixed>> */
    public static array $pools = [];

    /** @var list<array<string, mixed>> */
    public static array $images = [];

    public static int $status = 200;
}

/**
 * @param  list<array<string, mixed>>  $pools
 * @param  list<array<string, mixed>>  $images
 */
function cephAnswers(array $pools, array $images = [], int $status = 200): void
{
    CephState::$pools = $pools;
    CephState::$images = $images;
    CephState::$status = $status;

    Http::fake([
        'ceph.test/api/pool*' => fn () => Http::response(CephState::$pools, CephState::$status),
        'ceph.test/api/block/image*' => fn () => Http::response(CephState::$images, CephState::$status),
        'ceph.test/api/health/minimal*' => fn () => Http::response(['health' => ['status' => 'HEALTH_OK']]),
        'ceph.test/*' => fn () => Http::response([]),
    ]);
}

/**
 * @param  array<string, mixed>  $stats
 * @param  array<string, int>  $pgStatus
 * @return array<string, mixed>
 */
function cephPool(int $id, string $name, array $stats = [], array $pgStatus = ['active+clean' => 128]): array
{
    return [
        'pool' => $id,
        'pool_name' => $name,
        'type' => 'replicated',
        'size' => 3,
        'pg_status' => $pgStatus,
        'stats' => $stats,
    ];
}

function enableCeph(StaffUser $actor): void
{
    $record = app(InstallModule::class)->handle('storage-ceph', $actor);
    $manifest = app(ModuleCatalogue::class)->find('storage-ceph');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['base_url' => 'https://ceph.test', 'verify_tls' => false],
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);

    app(ActiveModules::class)->forget();
}

function discoverStorage(): RunSummary
{
    return app(TaskRegistry::class)->resolve(AutomationTask::Storage)->handle();
}

it('writes pools and their volumes into the graph', function (): void {
    cephAnswers(
        [cephPool(1, 'rbd', [
            'bytes_used' => ['latest' => 400_000_000_000],
            'max_avail' => ['latest' => 600_000_000_000],
        ])],
        [[
            'pool_name' => 'rbd',
            'value' => [
                ['name' => 'vm-101-disk-0', 'size' => 50_000_000_000, 'disk_usage' => 20_000_000_000],
                ['name' => 'vm-102-disk-0', 'size' => 30_000_000_000],
            ],
        ]],
    );

    enableCeph($this->admin);

    expect(discoverStorage()->changed)->toBe(1);

    $pool = ResourceNode::query()->where('kind', 'storage_pool')->sole();

    // The pool id, not its name: a pool can be renamed and the id cannot.
    expect($pool->node_key)->toBe('ceph/1')
        ->and($pool->label)->toBe('rbd')
        ->and($pool->attributes['health'] ?? null)->toBe(StorageHealth::Healthy->value)
        ->and($pool->attributes['replicas'] ?? null)->toBe(3);

    $volumes = ResourceNode::query()->where('kind', 'storage_volume')->pluck('node_key')->all();

    expect($volumes)->toEqual(['ceph/rbd/vm-101-disk-0', 'ceph/rbd/vm-102-disk-0']);

    // Containment points downward, which is what makes the impact walk work.
    $edges = ResourceEdge::query()->where('from_node_id', $pool->id)->get();

    expect($edges)->toHaveCount(2)
        ->and($edges->pluck('relation')->unique()->values()->all())->toBe([Relation::Contains]);
});

/**
 * Ceph does not report a pool total. What is left for a pool after
 * replication and the fullest OSD is `max_avail`, so the total is the sum —
 * and it moves when a different pool grows, which surprises everybody once.
 */
it('derives a pool total Ceph never reported, as telemetry', function (): void {
    cephAnswers([cephPool(1, 'rbd', [
        'bytes_used' => ['latest' => 400_000_000_000],
        'max_avail' => ['latest' => 600_000_000_000],
    ])]);

    enableCeph($this->admin);
    discoverStorage();

    $pool = ResourceNode::query()->where('kind', 'storage_pool')->sole();

    $total = ResourceMetric::query()
        ->where('resource_node_id', $pool->id)
        ->where('metric', MetricKind::DiskTotal->value)
        ->sole();

    $used = ResourceMetric::query()
        ->where('resource_node_id', $pool->id)
        ->where('metric', MetricKind::DiskUsed->value)
        ->sole();

    expect((int) $total->value)->toBe(1_000_000_000_000)
        ->and((int) $used->value)->toBe(400_000_000_000);

    // Capacity is telemetry, not an attribute: a second copy of a number that
    // changes every hour is one the daily rollup would never see.
    expect($pool->attributes)->not->toHaveKey('used_bytes');
});

/**
 * A pool with no figures is an honest gap, not a pool that is empty.
 */
it('writes no capacity for a pool that reported none', function (): void {
    cephAnswers([cephPool(1, 'rbd')]);

    enableCeph($this->admin);
    discoverStorage();

    expect(ResourceMetric::query()->count())->toBe(0);
});

/**
 * `HEALTH_WARN` is cluster-wide and says nothing about which pool is
 * affected. `pg_status` is per pool and is what an operator reads.
 */
it('reads a pool’s health from its own placement groups', function (): void {
    cephAnswers([
        cephPool(1, 'clean', pgStatus: ['active+clean' => 128]),
        cephPool(2, 'rebuilding', pgStatus: ['active+clean' => 100, 'active+undersized+degraded' => 28]),
        cephPool(3, 'broken', pgStatus: ['active+clean' => 100, 'incomplete' => 2]),
    ]);

    enableCeph($this->admin);
    discoverStorage();

    $health = ResourceNode::query()
        ->where('kind', 'storage_pool')
        ->get()
        ->mapWithKeys(fn (ResourceNode $node): array => [$node->label => $node->attributes['health'] ?? null])
        ->all();

    expect($health)->toBe([
        'clean' => StorageHealth::Healthy->value,
        // The member a three-state scale loses: serving every read, and one
        // more failure from losing data.
        'rebuilding' => StorageHealth::Degraded->value,
        // Worse wins: a pool that is both degraded and has an inactive PG is
        // critical, and checking "degraded" first would report the milder.
        'broken' => StorageHealth::Critical->value,
    ]);
});

/**
 * An image on a degraded pool is not itself degraded, and Ceph says nothing
 * about an image's own condition. Inheriting would assert what it did not say.
 */
it('does not give an image a health Ceph never reported', function (): void {
    cephAnswers(
        [cephPool(1, 'rbd', pgStatus: ['active+undersized+degraded' => 28])],
        [['pool_name' => 'rbd', 'value' => [['name' => 'vm-101-disk-0', 'size' => 1_000]]]],
    );

    enableCeph($this->admin);
    discoverStorage();

    $volume = ResourceNode::query()->where('kind', 'storage_volume')->sole();

    expect($volume->attributes['health'] ?? null)->toBe(StorageHealth::Unknown->value);
});

/**
 * The image endpoint answers grouped by pool. Reading it as a flat list
 * answers nothing at all, silently — which is the kind of parse that looks
 * like an empty cluster.
 */
it('reads images out of the group the API wraps them in', function (): void {
    cephAnswers([], [
        ['pool_name' => 'one', 'value' => [['name' => 'a']]],
        ['pool_name' => 'two', 'value' => [['name' => 'b'], ['name' => 'c']]],
    ]);

    enableCeph($this->admin);
    discoverStorage();

    expect(ResourceNode::query()->where('kind', 'storage_volume')->count())->toBe(3);
});

/**
 * A watcher's address is `10.0.0.4:0/1234567`, and the machine is the host.
 * The edge is written only where the graph already has that node: inventing
 * one would put hardware into the graph on the word of one adapter.
 */
it('links a volume to the workload holding it, when the graph knows it', function (): void {
    cephAnswers([], [[
        'pool_name' => 'rbd',
        'value' => [[
            'name' => 'vm-101-disk-0',
            'watchers' => [['address' => '10.0.0.4:0/1234567']],
        ]],
    ]]);

    $host = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'server',
        nodeKey: '10.0.0.4',
        label: 'hv-1',
    );

    enableCeph($this->admin);
    discoverStorage();

    $volume = ResourceNode::query()->where('kind', 'storage_volume')->sole();
    $edge = ResourceEdge::query()->where('to_node_id', $volume->id)->sole();

    expect($edge->from_node_id)->toBe($host->id)
        // Hosts, not Contains: a server does not contain a volume that lives
        // on an array somewhere else, it is the thing using it.
        ->and($edge->relation)->toBe(Relation::Hosts)
        ->and($volume->attributes['attached_to'] ?? null)->toBe('10.0.0.4');
});

/**
 * A volume attached to a machine this installation has never heard of is a
 * true and useful thing to see.
 */
it('keeps the attachment as a name when the graph has no node for it', function (): void {
    cephAnswers([], [[
        'pool_name' => 'rbd',
        'value' => [[
            'name' => 'vm-101-disk-0',
            'watchers' => [['address' => '203.0.113.9:0/1']],
        ]],
    ]]);

    enableCeph($this->admin);
    discoverStorage();

    $volume = ResourceNode::query()->where('kind', 'storage_volume')->sole();

    expect($volume->attributes['attached_to'] ?? null)->toBe('203.0.113.9')
        ->and(ResourceEdge::query()->where('to_node_id', $volume->id)->count())->toBe(0);
});

/**
 * The worst thing this sweep could do: an unreachable cluster read as one
 * that was decommissioned overnight.
 */
it('retires nothing when the cluster refuses to answer', function (): void {
    cephAnswers([cephPool(1, 'rbd')], [['pool_name' => 'rbd', 'value' => [['name' => 'a']]]]);

    enableCeph($this->admin);
    discoverStorage();

    $before = ResourceNode::query()->whereIn('kind', ['storage_pool', 'storage_volume'])->count();

    expect($before)->toBe(2);

    cephAnswers([], [], status: 503);

    $summary = discoverStorage();

    expect($summary->failed)->toBe(1)
        ->and(ResourceNode::query()->whereNotNull('retired_at')->count())->toBe(0);
});

/** A volume that really has gone is retired, and the row is kept. */
it('retires a volume the cluster has stopped naming', function (): void {
    cephAnswers([], [['pool_name' => 'rbd', 'value' => [['name' => 'a'], ['name' => 'b']]]]);

    enableCeph($this->admin);
    discoverStorage();

    cephAnswers([], [['pool_name' => 'rbd', 'value' => [['name' => 'a']]]]);
    discoverStorage();

    expect(ResourceNode::query()->where('kind', 'storage_volume')->count())->toBe(2)
        ->and(ResourceNode::query()->where('kind', 'storage_volume')->whereNull('retired_at')->sole()->label)
        ->toBe('a');
});

/**
 * Run it twice and the second changes nothing: the rule every automation task
 * is held to (ADR 0031).
 */
it('changes nothing the second time', function (): void {
    cephAnswers([cephPool(1, 'rbd')], [['pool_name' => 'rbd', 'value' => [['name' => 'a']]]]);
    enableCeph($this->admin);

    discoverStorage();
    $before = ResourceNode::query()->count();

    discoverStorage();

    expect(ResourceNode::query()->count())->toBe($before)
        ->and(ResourceNode::query()->whereNotNull('retired_at')->count())->toBe(0);
});

/** Every state an operator reads is named, in both languages. */
it('names every storage health in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (StorageHealth::cases() as $health) {
            expect(__($health->labelKey()))->not->toBe($health->labelKey());
        }
    }

    app()->setLocale('en');
});
