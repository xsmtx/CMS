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
use App\Domain\Infrastructure\Relation;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Topology discovery, driven through the real FortiGate package over faked
 * HTTP (`phase-c-plan.md` §6).
 *
 * Against the package rather than a mock, for the reason
 * `AdapterHealthSweepTest` gives: `ActiveModules` is final on purpose, and a
 * fake of it would be a fake of the thing under test.
 *
 * What is being pinned is the three rules the run exists to keep — the serial
 * is the identity, a port is qualified by its device, and the sweep retires
 * only what it wrote.
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
 * What the fake FortiGate is currently reporting.
 *
 * A static rather than a second `Http::fake()` call: faking twice adds a stub
 * rather than replacing the first, so a test that "changed the answer" would
 * change nothing.
 */
final class FortigateState
{
    /** @var list<array<string, mixed>> */
    public static array $interfaces = [];

    public static ?string $serial = 'FG100FTK20001234';
}

function fortigateAnswers(array $interfaces, ?string $serial = 'FG100FTK20001234'): void
{
    FortigateState::$interfaces = $interfaces;
    FortigateState::$serial = $serial;

    Http::fake([
        'fw1.test/api/v2/monitor/system/interface*' => fn () => Http::response([
            'results' => FortigateState::$interfaces,
        ]),
        'fw1.test/api/v2/monitor/system/status*' => fn () => Http::response(array_filter([
            'version' => 'v7.4.1',
            'model_name' => 'FortiGate-100F',
            'hostname' => 'fw1',
            'serial' => FortigateState::$serial,
            'uptime' => 864_000,
        ], static fn (mixed $value): bool => $value !== null)),
        'fw1.test/*' => fn () => Http::response(['results' => []]),
    ]);
}

function enableFortigate(StaffUser $actor): void
{
    $record = app(InstallModule::class)->handle('network-fortigate', $actor);
    $manifest = app(ModuleCatalogue::class)->find('network-fortigate');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['base_url' => 'https://fw1.test', 'vdom' => 'root', 'verify_tls' => false],
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);

    app(ActiveModules::class)->forget();
}

function discoverTopology(): RunSummary
{
    return app(TaskRegistry::class)->resolve(AutomationTask::Topology)->handle();
}

it('writes the device, its ports and its vlans into the graph', function (): void {
    fortigateAnswers([
        ['name' => 'port1', 'status' => 'up', 'speed' => 1000, 'alias' => 'uplink'],
        ['name' => 'port2.100', 'status' => 'up', 'vlanid' => 100, 'alias' => 'customers'],
    ]);

    enableFortigate($this->admin);

    expect(discoverTopology()->changed)->toBe(1);

    // The serial is the identity: a hostname is changed by whoever last
    // configured the box, and a node key that moved would leave the old node
    // behind as a device that had apparently vanished.
    $device = ResourceNode::query()->where('kind', 'network_device')->sole();

    expect($device->node_key)->toBe('FG100FTK20001234')
        ->and($device->label)->toBe('fw1')
        ->and($device->attributes['model'] ?? null)->toBe('FortiGate-100F')
        ->and($device->attributes['vendor'] ?? null)->toBe('Fortinet');

    // Qualified by the device, because `port1` is what half the network is
    // called and a node key is unique per organization.
    $ports = ResourceNode::query()->where('kind', 'device_port')->pluck('node_key')->all();

    expect($ports)->toEqual([
        'FG100FTK20001234/port1',
        'FG100FTK20001234/port2.100',
    ]);

    $vlan = ResourceNode::query()->where('kind', 'vlan')->sole();

    expect($vlan->node_key)->toBe('FG100FTK20001234/vlan/100')
        ->and($vlan->label)->toBe('customers');

    // Containment points downward, which is what makes the impact walk work
    // and what makes the boundary hide a container from the contained.
    $edges = ResourceEdge::query()->where('from_node_id', $device->id)->get();

    expect($edges)->toHaveCount(3)
        ->and($edges->pluck('relation')->unique()->values()->all())->toBe([Relation::Contains]);
});

/**
 * The spine §2 draws reaches all the way down, and an address hangs off the
 * **port** rather than off the device: an address is configured on an
 * interface, and a box with two interfaces on one subnet is a box where
 * knowing which one matters.
 */
it('hangs an interface address off the port it is configured on', function (): void {
    fortigateAnswers([
        ['name' => 'port1', 'status' => 'up', 'ip' => '198.51.100.1', 'mask' => '255.255.255.0'],
    ]);

    enableFortigate($this->admin);
    discoverTopology();

    $port = ResourceNode::query()->where('kind', 'device_port')->sole();
    $address = ResourceNode::query()->where('kind', 'ip_address')->sole();

    expect($address->node_key)->toBe('FG100FTK20001234/port1/198.51.100.1/24')
        ->and($address->label)->toBe('198.51.100.1/24');

    $edge = ResourceEdge::query()->where('to_node_id', $address->id)->sole();

    expect($edge->from_node_id)->toBe($port->id);
});

/**
 * Run it twice and the second changes nothing: the rule every automation task
 * is held to (ADR 0031), and the one that proves the unique key on
 * `(organization_id, kind, node_key)` is doing its job.
 */
it('changes nothing the second time', function (): void {
    fortigateAnswers([['name' => 'port1', 'status' => 'up']]);
    enableFortigate($this->admin);

    discoverTopology();

    $before = ResourceNode::query()->count();

    discoverTopology();

    expect(ResourceNode::query()->count())->toBe($before)
        ->and(ResourceNode::query()->whereNotNull('retired_at')->count())->toBe(0);
});

/**
 * A port pulled out of a chassis stops being reported, and the graph has to
 * notice. Retiring rather than deleting, because "who was on port 3 in March"
 * is the question the graph exists to answer.
 */
it('retires a port the device stops reporting', function (): void {
    fortigateAnswers([
        ['name' => 'port1', 'status' => 'up'],
        ['name' => 'port2', 'status' => 'up'],
    ]);

    enableFortigate($this->admin);
    discoverTopology();

    expect(ResourceNode::query()->where('kind', 'device_port')->count())->toBe(2);

    fortigateAnswers([['name' => 'port1', 'status' => 'up']]);
    discoverTopology();

    $retired = ResourceNode::query()
        ->where('kind', 'device_port')
        ->whereNotNull('retired_at')
        ->sole();

    expect($retired->node_key)->toBe('FG100FTK20001234/port2');

    // The row is still there. A deletion would take the history with it.
    expect(ResourceNode::query()->where('kind', 'device_port')->count())->toBe(2);
});

/**
 * It retires only what it wrote. Retiring by kind instead would take a second
 * adapter's ports with it every time this one ran — and core has the same rule
 * about never believing it owns rows it has never seen.
 */
it('leaves another source alone', function (): void {
    fortigateAnswers([['name' => 'port1', 'status' => 'up']]);
    enableFortigate($this->admin);

    $theirs = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'device_port',
        nodeKey: 'somebody-else/port9',
        label: 'port9',
        source: 'topology:other',
    );

    discoverTopology();

    expect($theirs->fresh()?->retired_at)->toBeNull();
});

/**
 * A device with no serial is keyed by the adapter, not by the hostname: a
 * hostname moves, and a node key that moved would leave the old node behind
 * as a device that had apparently vanished.
 */
it('falls back to the adapter key when the device reports no serial', function (): void {
    fortigateAnswers([['name' => 'port1', 'status' => 'up']], serial: null);
    enableFortigate($this->admin);

    discoverTopology();

    expect(ResourceNode::query()->where('kind', 'network_device')->sole()->node_key)
        ->toBe('fortigate');
});

/**
 * An unreachable device is one failed item, not a failed run — the sweep rule
 * every automation task follows.
 */
it('records a device that did not answer without stopping', function (): void {
    Http::fake(['fw1.test/*' => Http::response('gone', 503)]);

    enableFortigate($this->admin);

    $summary = discoverTopology();

    expect($summary->failed)->toBe(1)
        ->and($summary->examined)->toBe(1)
        ->and(ResourceNode::query()->where('kind', 'network_device')->count())->toBe(0);
});
