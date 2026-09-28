<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\Virtualisation\MachineState;
use App\Domain\Infrastructure\Virtualisation\PowerAction;
use App\Domain\Organizations\OrganizationType;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Virtual machines and the guarded power action, driven through the real
 * Proxmox fleet package over faked HTTP (`phase-f-plan.md` §5 step 5).
 *
 * This is the most consequential action in the product: it does not stop a
 * customer's service, it stops the machine several customers are on. What is
 * pinned is every guard on it — the permission above `auth.recent`, the
 * machine's own name typed out, the state re-read from the hypervisor at the
 * moment of the call rather than from the page, and the read-back afterwards
 * that catches a hypervisor which accepted the command and did nothing.
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
 * What the fake Proxmox is currently reporting.
 *
 * A static rather than a second `Http::fake()` call: faking twice adds a stub
 * rather than replacing the first.
 */
final class ProxmoxState
{
    /** @var list<array<string, mixed>> */
    public static array $nodes = [];

    /** @var array<string, list<array<string, mixed>>> */
    public static array $guests = [];

    public static int $status = 200;

    /** @var list<string> */
    public static array $commands = [];

    /** Whether `status/current` can describe a machine at all. */
    public static bool $describable = true;
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @param  array<string, list<array<string, mixed>>>  $guests  Keyed by "node/type".
 */
function proxmoxAnswers(array $nodes, array $guests = [], int $status = 200): void
{
    ProxmoxState::$nodes = $nodes;
    ProxmoxState::$guests = $guests;
    ProxmoxState::$status = $status;
    ProxmoxState::$commands = [];
    ProxmoxState::$describable = true;

    Http::fake([
        'pve.test/api2/json/version*' => fn () => Http::response(['data' => ['version' => '8.2']]),
        'pve.test/api2/json/nodes' => fn () => Http::response(
            ['data' => ProxmoxState::$nodes],
            ProxmoxState::$status,
        ),
        'pve.test/api2/json/nodes/*' => function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $parts = array_values(array_filter(explode('/', $path)));

            // api2/json/nodes/<node>/<type>[/<vmid>/status/<verb>]
            $node = $parts[3] ?? '';
            $type = $parts[4] ?? '';

            if (count($parts) === 5) {
                return Http::response(
                    ['data' => ProxmoxState::$guests[$node.'/'.$type] ?? []],
                    ProxmoxState::$status,
                );
            }

            $vmid = $parts[5] ?? '';
            $verb = $parts[7] ?? '';

            if ($verb === 'current') {
                if (! ProxmoxState::$describable) {
                    return Http::response(['data' => null]);
                }

                foreach (ProxmoxState::$guests[$node.'/'.$type] ?? [] as $guest) {
                    if ((string) ($guest['vmid'] ?? '') === $vmid) {
                        return Http::response(['data' => $guest]);
                    }
                }

                return Http::response(['data' => null]);
            }

            ProxmoxState::$commands[] = $node.'/'.$type.'/'.$vmid.'/'.$verb;

            // The cluster did as it was told, so the next read reflects it.
            foreach (ProxmoxState::$guests[$node.'/'.$type] ?? [] as $index => $guest) {
                if ((string) ($guest['vmid'] ?? '') !== $vmid) {
                    continue;
                }

                ProxmoxState::$guests[$node.'/'.$type][$index]['status'] = match ($verb) {
                    'start', 'reboot' => 'running',
                    default => 'stopped',
                };
                unset(ProxmoxState::$guests[$node.'/'.$type][$index]['qmpstatus']);
            }

            return Http::response(['data' => 'UPID:task'], ProxmoxState::$status);
        },
        'pve.test/*' => fn () => Http::response(['data' => []]),
    ]);
}

function enableProxmoxFleet(StaffUser $actor): void
{
    $record = app(InstallModule::class)->handle('hypervisor-proxmox', $actor);
    $manifest = app(ModuleCatalogue::class)->find('hypervisor-proxmox');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['base_url' => 'https://pve.test', 'token_id' => 'infracms@pve!fleet', 'verify_tls' => false],
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);
    app(ActiveModules::class)->forget();
}

/**
 * What an operator does on the Adapters screen, and only after the first
 * sweep: the `resource_adapters` row does not exist until the registry has
 * synced it.
 */
function allowProxmoxWrites(): void
{
    $updated = ResourceAdapter::query()
        ->where('adapter_key', 'proxmox-fleet')
        ->update(['writes_enabled' => true]);

    expect($updated)->toBe(1);
}

function discoverMachines(): RunSummary
{
    return app(TaskRegistry::class)->resolve(AutomationTask::Machines)->handle();
}

function proxmoxFleet(): void
{
    proxmoxAnswers(
        [['node' => 'hv-1', 'status' => 'online', 'maxcpu' => 32, 'maxmem' => 137_438_953_472, 'mem' => 68_719_476_736, 'cpu' => 0.24, 'uptime' => 864_000]],
        ['hv-1/qemu' => [
            ['vmid' => 101, 'name' => 'web-1', 'status' => 'running', 'maxmem' => 4_294_967_296, 'maxdisk' => 53_687_091_200, 'cpus' => 2],
            ['vmid' => 102, 'name' => 'web-2', 'status' => 'stopped', 'maxmem' => 4_294_967_296, 'cpus' => 2],
        ], 'hv-1/lxc' => [
            ['vmid' => 201, 'name' => 'mail-1', 'status' => 'running', 'maxmem' => 1_073_741_824, 'cpus' => 1],
        ]],
    );
}

it('writes hosts and the machines on them into the graph', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);

    expect(discoverMachines()->changed)->toBe(1);

    $host = ResourceNode::query()->where('kind', 'hypervisor_host')->sole();

    expect($host->node_key)->toBe('proxmox-fleet/hv-1')
        ->and($host->attributes['online'])->toBeTrue();

    $machines = ResourceNode::query()->where('kind', 'virtual_machine')->orderBy('label')->get();

    expect($machines)->toHaveCount(3)
        // The key addresses the machine: node, type and vmid. A bare vmid
        // would need a lookup before every action.
        ->and($machines->pluck('node_key')->all())->toEqual([
            'proxmox-fleet/hv-1/lxc/201',
            'proxmox-fleet/hv-1/qemu/101',
            'proxmox-fleet/hv-1/qemu/102',
        ]);

    // Hosts, which is what a hypervisor does — and containment points
    // downward, so the impact walk reaches every machine on the host.
    $edges = ResourceEdge::query()->where('from_node_id', $host->id)->get();

    expect($edges)->toHaveCount(3)
        ->and($edges->pluck('relation')->unique()->values()->all())->toBe([Relation::Hosts]);
});

it('records a host’s capacity as telemetry rather than as attributes', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();

    $host = ResourceNode::query()->where('kind', 'hypervisor_host')->sole();

    $metrics = ResourceMetric::query()
        ->where('resource_node_id', $host->id)
        ->pluck('value', 'metric')
        ->all();

    expect($metrics)->toHaveKey(MetricKind::MemoryTotal->value)
        ->and((int) $metrics[MetricKind::MemoryUsed->value])->toBe(68_719_476_736)
        ->and($host->attributes)->not->toHaveKey('memory_used');
});

/**
 * A paused machine reports `running` with `qmpstatus: paused`. Reading only
 * `status` would show it as serving traffic — and it is exactly the machine
 * holding all its memory and answering nothing.
 */
it('tells a paused machine from a running one', function (): void {
    proxmoxAnswers(
        [['node' => 'hv-1', 'status' => 'online']],
        ['hv-1/qemu' => [
            ['vmid' => 101, 'name' => 'busy', 'status' => 'running'],
            ['vmid' => 102, 'name' => 'forgotten', 'status' => 'running', 'qmpstatus' => 'paused'],
            ['vmid' => 103, 'name' => 'off', 'status' => 'stopped'],
        ]],
    );

    enableProxmoxFleet($this->admin);
    discoverMachines();

    $states = ResourceNode::query()
        ->where('kind', 'virtual_machine')
        ->get()
        ->mapWithKeys(fn (ResourceNode $node): array => [$node->label => $node->attributes['state']])
        ->all();

    expect($states)->toBe([
        'busy' => MachineState::Running->value,
        'forgotten' => MachineState::Paused->value,
        'off' => MachineState::Stopped->value,
    ]);
});

/** A node the cluster cannot see has not failed. */
it('reads an unknown node status as nothing rather than as offline', function (): void {
    proxmoxAnswers([['node' => 'hv-1', 'status' => 'unknown']]);

    enableProxmoxFleet($this->admin);
    discoverMachines();

    expect(ResourceNode::query()->where('kind', 'hypervisor_host')->sole()->attributes)
        ->not->toHaveKey('online');
});

/**
 * The worst thing this sweep could do: a cluster that is merely unreachable
 * read as one whose machines have all gone.
 */
it('retires nothing when the cluster refuses to answer', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();

    expect(ResourceNode::query()->whereIn('kind', ['hypervisor_host', 'virtual_machine'])->count())->toBe(4);

    proxmoxAnswers([], [], status: 503);

    expect(discoverMachines()->failed)->toBe(1)
        ->and(ResourceNode::query()->whereNotNull('retired_at')->count())->toBe(0);
});

it('shuts a machine down and reads it back', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();
    allowProxmoxWrites();

    $machine = ResourceNode::query()->where('label', 'web-1')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/machines/{$machine->id}/power", [
            'action' => PowerAction::Shutdown->value,
            'reason' => 'Kernel upgrade',
            'confirm' => 'web-1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // `shutdown` and `stop` are different endpoints, not one with a flag.
    expect(ProxmoxState::$commands)->toBe(['hv-1/qemu/101/shutdown'])
        ->and($machine->fresh()->attributes['state'])->toBe(MachineState::Stopped->value);
});

/** Cutting the power is a different endpoint and a different promise. */
it('cuts the power through the other endpoint', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();
    allowProxmoxWrites();

    $machine = ResourceNode::query()->where('label', 'web-1')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/machines/{$machine->id}/power", [
            'action' => PowerAction::PowerOff->value,
            'reason' => 'It stopped answering',
            'confirm' => 'web-1',
        ])
        ->assertSessionHasNoErrors();

    expect(ProxmoxState::$commands)->toBe(['hv-1/qemu/101/stop']);
});

/**
 * The state is checked against the hypervisor at the moment of the call, not
 * against the page the operator was looking at. A `power_off` sent to a
 * machine somebody started thirty seconds ago is not harmless.
 */
it('refuses to stop a machine the hypervisor says is already stopped', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();
    allowProxmoxWrites();

    $machine = ResourceNode::query()->where('label', 'web-2')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/machines/{$machine->id}/power", [
            'action' => PowerAction::Shutdown->value,
            'reason' => 'Belt and braces',
            'confirm' => 'web-2',
        ])
        ->assertSessionHasErrors('reason');

    expect(ProxmoxState::$commands)->toBe([]);
});

/**
 * The hypervisor took the command and cannot now say what the machine is
 * doing. Not a success: somebody is about to go and work on it.
 */
it('refuses when the hypervisor cannot describe the machine afterwards', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();
    allowProxmoxWrites();

    $machine = ResourceNode::query()->where('label', 'web-1')->sole();

    ProxmoxState::$describable = false;

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/machines/{$machine->id}/power", [
            'action' => PowerAction::Shutdown->value,
            'reason' => 'Kernel upgrade',
            'confirm' => 'web-1',
        ])
        ->assertSessionHasErrors('reason');
});

/**
 * The typed name is checked on the server as well as in the dialog: a dialog
 * is a convenience and this is the guard.
 */
it('refuses a power action whose typed name does not match', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();
    allowProxmoxWrites();

    $machine = ResourceNode::query()->where('label', 'web-1')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/machines/{$machine->id}/power", [
            'action' => PowerAction::PowerOff->value,
            'reason' => 'Wrong machine',
            'confirm' => 'web-2',
        ])
        ->assertSessionHasErrors('confirm');

    expect(ProxmoxState::$commands)->toBe([]);
});

/**
 * The permission is above `auth.recent`, so somebody who may not do this is
 * refused before being asked to confirm a password.
 */
it('refuses somebody without the permission, without asking for a password', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();
    allowProxmoxWrites();

    $machine = ResourceNode::query()->where('label', 'web-1')->sole();
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->post("/admin/infrastructure/machines/{$machine->id}/power", [
            'action' => PowerAction::Shutdown->value,
            'reason' => 'No',
            'confirm' => 'web-1',
        ])
        ->assertForbidden();

    expect(ProxmoxState::$commands)->toBe([]);
});

it('refuses a power action when writes are not enabled', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();

    $machine = ResourceNode::query()->where('label', 'web-1')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/machines/{$machine->id}/power", [
            'action' => PowerAction::Shutdown->value,
            'reason' => 'Try it',
            'confirm' => 'web-1',
        ])
        ->assertSessionHasErrors('reason');

    expect(ProxmoxState::$commands)->toBe([]);
});

it('answers 404 for a node that is not a machine', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();

    $host = ResourceNode::query()->where('kind', 'hypervisor_host')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/machines/{$host->id}/power", [
            'action' => PowerAction::Shutdown->value,
            'reason' => 'No',
            'confirm' => 'hv-1',
        ])
        ->assertNotFound();
});

it('drives the screen, hosts with their machines underneath', function (): void {
    proxmoxFleet();
    enableProxmoxFleet($this->admin);
    discoverMachines();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/machines')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Infrastructure/VirtualMachines')
            ->has('hosts', 1)
            ->has('hosts.0.machines', 3)
            ->has('unplaced', 0)
            ->where('can.power', true)
            // Two fields, as everything that crosses to the browser is.
            ->where('hosts.0.machines.1.state', MachineState::Running->value)
            ->where('hosts.0.machines.1.stateTone', 'healthy')
            // Every action named, with what kind of promise it is.
            ->has('actions', 4)
            ->where('actions.2.value', PowerAction::PowerOff->value)
            ->where('actions.2.abrupt', true)
            ->where('actions.0.stopsService', false));
});

it('refuses the screen to somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/infrastructure/machines')
        ->assertForbidden();
});

/** Every state and every action an operator reads is named, in both languages. */
it('names every machine state and power action in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (MachineState::cases() as $state) {
            expect(__($state->labelKey()))->not->toBe($state->labelKey());
        }

        foreach (PowerAction::cases() as $action) {
            expect(__($action->labelKey()))->not->toBe($action->labelKey());
        }
    }

    app()->setLocale('en');
});
