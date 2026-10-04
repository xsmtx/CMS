<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\Runs\DiscoverKubernetes;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Infrastructure\Relation;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Hosting-service context over a cluster (§25).
 *
 * The question §25 names is who is affected when a node drains, and the whole
 * design is arranged to answer it through the graph: cluster contains node,
 * node hosts workload, and where a cluster node is plainly a `servers` row the
 * server hosts the cluster node — which is what lets a walk reach the services
 * a customer bought.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();
});

afterEach(function (): void {
    ClusterState::reset();
});

/** What the fake cluster currently reports. */
final class ClusterState
{
    public static bool $cordoned = false;

    public static string $ready = 'True';

    public static int $readyReplicas = 3;

    /** Hostnames both nodes report, so ambiguity can be forced. */
    public static string $secondHostname = 'web-2.dc2';

    public static function reset(): void
    {
        self::$cordoned = false;
        self::$ready = 'True';
        self::$readyReplicas = 3;
        self::$secondHostname = 'web-2.dc2';
    }
}

function clusterAnswers(): void
{
    ClusterState::reset();

    Http::fake([
        'k8s.test/version' => fn () => Http::response(['gitVersion' => 'v1.31.2']),
        'k8s.test/api/v1/nodes*' => fn () => Http::response(['items' => [
            [
                'metadata' => ['name' => 'node-a'],
                'spec' => ['unschedulable' => ClusterState::$cordoned],
                'status' => [
                    // Several conditions, and `Ready` is not the first: the
                    // trap this parse exists for.
                    'conditions' => [
                        ['type' => 'MemoryPressure', 'status' => 'False'],
                        ['type' => 'Ready', 'status' => ClusterState::$ready],
                    ],
                    'addresses' => [
                        ['type' => 'InternalIP', 'address' => '10.0.0.4'],
                        ['type' => 'Hostname', 'address' => 'web-1.dc2'],
                    ],
                    'nodeInfo' => ['kubeletVersion' => 'v1.31.2'],
                ],
            ],
            [
                'metadata' => ['name' => 'node-b'],
                'spec' => [],
                'status' => [
                    'conditions' => [['type' => 'Ready', 'status' => 'True']],
                    'addresses' => [['type' => 'Hostname', 'address' => ClusterState::$secondHostname]],
                    'nodeInfo' => ['kubeletVersion' => 'v1.31.2'],
                ],
            ],
        ]]),
        'k8s.test/apis/apps/v1/deployments*' => fn () => Http::response(['items' => [
            [
                'metadata' => ['name' => 'shop', 'namespace' => 'customer-7'],
                'status' => ['replicas' => 3, 'readyReplicas' => ClusterState::$readyReplicas],
            ],
        ]]),
        'k8s.test/api/v1/pods*' => fn () => Http::response(['items' => [
            [
                'metadata' => ['name' => 'shop-abc', 'namespace' => 'customer-7', 'labels' => ['app' => 'shop']],
                'spec' => ['nodeName' => 'node-a'],
            ],
            [
                'metadata' => ['name' => 'shop-def', 'namespace' => 'customer-7', 'labels' => ['app' => 'shop']],
                'spec' => ['nodeName' => 'node-b'],
            ],
            // Unscheduled: no node at all, which must not place it on a node
            // called "".
            [
                'metadata' => ['name' => 'shop-ghi', 'namespace' => 'customer-7', 'labels' => ['app' => 'shop']],
                'spec' => [],
            ],
        ]]),
        'k8s.test/*' => fn () => Http::response(['items' => []]),
    ]);
}

function enableCluster(StaffUser $actor): void
{
    $record = app(InstallModule::class)->handle('kubernetes', $actor);
    $manifest = app(ModuleCatalogue::class)->find('kubernetes');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['base_url' => 'https://k8s.test', 'cluster_name' => 'dc2', 'verify_tls' => false],
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);
    app(ActiveModules::class)->forget();

    ResourceAdapter::query()->updateOrCreate(
        ['organization_id' => test()->provider->id, 'adapter_key' => 'kubernetes'],
        ['name' => 'Kubernetes', 'vendor' => 'Kubernetes', 'enabled' => true, 'writes_enabled' => false],
    );
}

function nodesOfKind(string $kind): Collection
{
    return ResourceNode::query()->where('kind', $kind)->whereNull('retired_at')->get();
}

it('writes the cluster, its machines and what is running on them', function (): void {
    clusterAnswers();
    enableCluster($this->admin);

    app(DiscoverKubernetes::class)->handle();

    expect(nodesOfKind('k8s_cluster'))->toHaveCount(1)
        ->and(nodesOfKind('k8s_node'))->toHaveCount(2)
        ->and(nodesOfKind('k8s_workload'))->toHaveCount(1);

    $workload = nodesOfKind('k8s_workload')->first();

    // The namespace in the label, because `shop` is what half a cluster is
    // called and the namespace is what tells two of them apart.
    expect($workload?->label)->toBe('customer-7/shop');
});

it('reads the Ready condition rather than the first one in the list', function (): void {
    clusterAnswers();
    enableCluster($this->admin);

    app(DiscoverKubernetes::class)->handle();

    $node = nodesOfKind('k8s_node')->firstWhere('label', 'node-a');

    // MemoryPressure is first and `False`; reading it would report a healthy
    // node as critical on every cluster there is.
    expect($node?->attributes['health'] ?? null)->toBe('healthy');
});

it('keeps cordoned apart from unhealthy', function (): void {
    clusterAnswers();
    ClusterState::$cordoned = true;
    enableCluster($this->admin);

    app(DiscoverKubernetes::class)->handle();

    $node = nodesOfKind('k8s_node')->firstWhere('label', 'node-a');

    // Somebody drained it on purpose. One column for both would make planned
    // maintenance look like a fault.
    expect($node?->attributes['schedulable'] ?? null)->toBeFalse()
        ->and($node?->attributes['health'] ?? null)->toBe('healthy');
});

it('calls a node that stopped reporting unknown, not critical', function (): void {
    clusterAnswers();
    ClusterState::$ready = 'Unknown';
    enableCluster($this->admin);

    app(DiscoverKubernetes::class)->handle();

    // `"Unknown"` is the string a kubelet that went quiet produces, and it is
    // not the same answer as `"False"`.
    expect(nodesOfKind('k8s_node')->firstWhere('label', 'node-a')?->attributes['health'] ?? null)
        ->toBe('unknown');
});

it('calls a partly-running workload degraded and an empty one critical', function (): void {
    clusterAnswers();
    ClusterState::$readyReplicas = 1;
    enableCluster($this->admin);

    app(DiscoverKubernetes::class)->handle();

    expect(nodesOfKind('k8s_workload')->first()?->attributes['health'] ?? null)->toBe('degraded');

    ClusterState::$readyReplicas = 0;
    app(DiscoverKubernetes::class)->handle();

    expect(nodesOfKind('k8s_workload')->first()?->attributes['health'] ?? null)->toBe('critical');
});

it('places a workload on the machines its pods are actually on', function (): void {
    clusterAnswers();
    enableCluster($this->admin);

    app(DiscoverKubernetes::class)->handle();

    $workload = nodesOfKind('k8s_workload')->first();

    $edges = ResourceEdge::query()
        ->where('to_node_id', $workload?->id)
        ->where('relation', Relation::Hosts->value)
        ->whereNull('ended_at')
        ->count();

    // Two pods on two nodes, and the unscheduled third placed nowhere.
    expect($edges)->toBe(2);
});

/**
 * The edge that joins the two halves, and the one §25 is really about.
 */
it('hangs a cluster node off the server it plainly is', function (): void {
    clusterAnswers();
    enableCluster($this->admin);

    $server = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'server',
        nodeKey: 'srv-1',
        // Matched on the label, lower-cased.
        label: 'Web-1.DC2',
        source: 'core',
    );

    app(DiscoverKubernetes::class)->handle();

    $node = nodesOfKind('k8s_node')->firstWhere('label', 'node-a');

    expect(ResourceEdge::query()
        ->where('from_node_id', $server->id)
        ->where('to_node_id', $node?->id)
        ->whereNull('ended_at')
        ->exists())->toBeTrue();
});

it('refuses an ambiguous hostname rather than attaching to the wrong machine', function (): void {
    clusterAnswers();

    // Both cluster nodes now claim the same hostname.
    ClusterState::$secondHostname = 'web-1.dc2';

    enableCluster($this->admin);

    app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'server',
        nodeKey: 'srv-1',
        label: 'web-1.dc2',
        source: 'core',
    );

    app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'server',
        nodeKey: 'srv-2',
        label: 'web-1.dc2',
        source: 'core',
    );

    app(DiscoverKubernetes::class)->handle();

    // Attached to the wrong machine is worse than attached to nothing: the
    // first one gets acted on.
    $placed = ResourceEdge::query()
        ->whereIn('from_node_id', ResourceNode::query()->where('kind', 'server')->pluck('id'))
        ->whereNull('ended_at')
        ->count();

    expect($placed)->toBe(0);
});

it('retires only what it wrote, and nothing on a failed read', function (): void {
    clusterAnswers();
    enableCluster($this->admin);

    $other = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'k8s_node',
        nodeKey: 'someone-else/node-z',
        label: 'node-z',
        // A second adapter's node. Retiring by kind would take it every time.
        source: 'k8s:other',
    );

    app(DiscoverKubernetes::class)->handle();

    expect($other->fresh()?->retired_at)->toBeNull();

    // Now the cluster stops answering. Nothing is retired, because a cluster
    // that is merely unreachable must not look like one somebody tore down.
    Http::fake(['k8s.test/*' => fn () => Http::response('gone', 503)]);

    app(DiscoverKubernetes::class)->handle();

    expect(nodesOfKind('k8s_node'))->toHaveCount(3);
});
