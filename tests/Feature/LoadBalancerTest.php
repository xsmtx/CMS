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
use App\Domain\Infrastructure\LoadBalancing\BackendState;
use App\Domain\Infrastructure\Relation;
use App\Domain\Organizations\OrganizationType;
use App\Http\Middleware\RequireRecentAuthentication;
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
use Illuminate\Support\Facades\Http;

/**
 * Load balancers, driven through the real HAProxy package over faked HTTP
 * (`phase-f-plan.md` §5 step 4).
 *
 * What is pinned is the decisions. The administrative state wins over the
 * operational one, because a server somebody drained is still operationally
 * up. A drain is a guarded *action* rather than a guarded change, so the gate
 * is a permission and the password challenge. And the balancer is read back
 * afterwards, because a balancer that took the command and did nothing is the
 * failure worth catching and no adapter reports it about itself.
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
 * What the fake HAProxy is currently reporting.
 *
 * A static rather than a second `Http::fake()` call: faking twice adds a stub
 * rather than replacing the first, so a test that "changed the answer" would
 * change nothing.
 */
final class HaproxyState
{
    /** @var list<string> */
    public static array $backends = [];

    /** @var array<string, list<array<string, mixed>>> */
    public static array $servers = [];

    public static int $status = 200;

    /** @var list<array<string, mixed>> */
    public static array $writes = [];
}

/**
 * @param  list<string>  $backends
 * @param  array<string, list<array<string, mixed>>>  $servers
 */
function haproxyAnswers(array $backends, array $servers = [], int $status = 200): void
{
    HaproxyState::$backends = $backends;
    HaproxyState::$servers = $servers;
    HaproxyState::$status = $status;
    HaproxyState::$writes = [];

    Http::fake([
        'lb1.test/v2/services/haproxy/configuration/backends*' => fn () => Http::response(
            // The configuration endpoints wrap their answer in `{_version, data}`.
            ['_version' => 3, 'data' => array_map(
                static fn (string $name): array => ['name' => $name],
                HaproxyState::$backends,
            )],
            HaproxyState::$status,
        ),
        'lb1.test/v2/services/haproxy/runtime/servers/*' => function ($request) {
            HaproxyState::$writes[] = [
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            // The balancer accepted it, so the next read reflects it.
            $state = $request->data()['admin_state'] ?? 'ready';

            foreach (HaproxyState::$servers as $backend => $servers) {
                foreach ($servers as $index => $server) {
                    if (str_contains($request->url(), '/'.$server['name'])) {
                        HaproxyState::$servers[$backend][$index]['admin_state'] = $state;
                    }
                }
            }

            return Http::response(['name' => 'ok'], HaproxyState::$status);
        },
        'lb1.test/v2/services/haproxy/runtime/servers*' => function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $backend = is_string($query['backend'] ?? null) ? $query['backend'] : '';

            // The runtime endpoints answer a bare list, unlike the others.
            return Http::response(HaproxyState::$servers[$backend] ?? [], HaproxyState::$status);
        },
        'lb1.test/v2/info*' => fn () => Http::response(['api' => ['version' => '2.9']]),
        'lb1.test/*' => fn () => Http::response([]),
    ]);
}

function enableHaproxy(StaffUser $actor): void
{
    $record = app(InstallModule::class)->handle('loadbalancer-haproxy', $actor);
    $manifest = app(ModuleCatalogue::class)->find('loadbalancer-haproxy');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['base_url' => 'https://lb1.test', 'username' => 'dataplane', 'verify_tls' => false],
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);
    app(ActiveModules::class)->forget();
}

/**
 * What an operator does on the Adapters screen, deliberately and audibly.
 *
 * **After the first sweep, not before.** The `resource_adapters` row does not
 * exist until the registry has synced it, which happens the first time
 * anything asks the registry for this organization's adapters — so an update
 * written before that matches nothing and silently does not happen.
 */
function allowHaproxyWrites(): void
{
    $updated = ResourceAdapter::query()
        ->where('adapter_key', 'haproxy')
        ->update(['writes_enabled' => true]);

    expect($updated)->toBe(1);
}

function discoverLoadBalancers(): RunSummary
{
    return app(TaskRegistry::class)->resolve(AutomationTask::LoadBalancers)->handle();
}

it('writes listeners and their backends into the graph', function (): void {
    haproxyAnswers(['web'], ['web' => [
        ['name' => 'web-1', 'address' => '10.0.0.1', 'port' => 80, 'weight' => 100, 'operational_state' => 'up'],
        ['name' => 'web-2', 'address' => '10.0.0.2', 'port' => 80, 'operational_state' => 'down'],
    ]]);

    enableHaproxy($this->admin);

    expect(discoverLoadBalancers()->changed)->toBe(1);

    $listener = ResourceNode::query()->where('kind', 'lb_listener')->sole();

    expect($listener->node_key)->toBe('haproxy/web');

    $backends = ResourceNode::query()->where('kind', 'lb_backend')->get();

    expect($backends)->toHaveCount(2)
        // Qualified by the listener, because `web-1` is behind four of them.
        ->and($backends->pluck('node_key')->all())->toEqual(['haproxy/web/web-1', 'haproxy/web/web-2'])
        ->and($backends->firstWhere('label', 'web-1')->attributes['state'])->toBe(BackendState::Up->value)
        ->and($backends->firstWhere('label', 'web-2')->attributes['state'])->toBe(BackendState::Down->value);

    $edges = ResourceEdge::query()->where('from_node_id', $listener->id)->get();

    expect($edges)->toHaveCount(2)
        ->and($edges->pluck('relation')->unique()->values()->all())->toBe([Relation::Contains]);
});

/**
 * A server somebody drained is still operationally up and must not read as
 * taking traffic; one in maintenance is not failing a health check.
 */
it('lets the administrative state win over the operational one', function (): void {
    haproxyAnswers(['web'], ['web' => [
        ['name' => 'drained', 'operational_state' => 'up', 'admin_state' => 'drain'],
        ['name' => 'maintained', 'operational_state' => 'up', 'admin_state' => 'maint'],
        ['name' => 'serving', 'operational_state' => 'up', 'admin_state' => 'ready'],
        // The operational way of saying draining, which a server reaches on
        // its own.
        ['name' => 'stopping', 'operational_state' => 'stopping'],
    ]]);

    enableHaproxy($this->admin);
    discoverLoadBalancers();

    $states = ResourceNode::query()
        ->where('kind', 'lb_backend')
        ->get()
        ->mapWithKeys(fn (ResourceNode $node): array => [$node->label => $node->attributes['state']])
        ->all();

    expect($states)->toBe([
        'drained' => BackendState::Draining->value,
        'maintained' => BackendState::Disabled->value,
        'serving' => BackendState::Up->value,
        'stopping' => BackendState::Draining->value,
    ]);
});

/**
 * "Which balancers is this server behind" has to be answerable before
 * anybody can take a machine out of service safely.
 */
it('links a backend to the machine it is, when the graph knows it', function (): void {
    haproxyAnswers(['web'], ['web' => [
        ['name' => 'web-1', 'address' => '10.0.0.1', 'operational_state' => 'up'],
    ]]);

    $machine = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'server',
        nodeKey: '10.0.0.1',
        label: 'web-1',
    );

    enableHaproxy($this->admin);
    discoverLoadBalancers();

    $backend = ResourceNode::query()->where('kind', 'lb_backend')->sole();

    // Two edges reach a backend: the listener contains it, and the machine
    // serves it. Asking for the one by relation is the point of the test.
    $edge = ResourceEdge::query()
        ->where('to_node_id', $backend->id)
        ->where('relation', Relation::Serves->value)
        ->sole();

    expect($edge->from_node_id)->toBe($machine->id);
});

/**
 * The worst thing this sweep could do: a balancer that is merely unreachable
 * read as one whose backends have all been removed.
 */
it('retires nothing when the balancer refuses to answer', function (): void {
    haproxyAnswers(['web'], ['web' => [['name' => 'web-1', 'operational_state' => 'up']]]);

    enableHaproxy($this->admin);
    discoverLoadBalancers();

    expect(ResourceNode::query()->whereIn('kind', ['lb_listener', 'lb_backend'])->count())->toBe(2);

    haproxyAnswers([], [], status: 503);

    expect(discoverLoadBalancers()->failed)->toBe(1)
        ->and(ResourceNode::query()->whereNotNull('retired_at')->count())->toBe(0);
});

it('drains a backend and reads it back', function (): void {
    haproxyAnswers(['web'], ['web' => [
        ['name' => 'web-1', 'address' => '10.0.0.1', 'operational_state' => 'up', 'admin_state' => 'ready'],
        ['name' => 'web-2', 'address' => '10.0.0.2', 'operational_state' => 'up', 'admin_state' => 'ready'],
    ]]);

    enableHaproxy($this->admin);
    discoverLoadBalancers();
    allowHaproxyWrites();

    $backend = ResourceNode::query()->where('label', 'web-1')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/load-balancers/{$backend->id}/drain", [
            'reason' => 'Rebooting for a kernel upgrade',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The whole body is the one field: sending the server's other fields
    // back would overwrite whatever somebody changed in between.
    expect(HaproxyState::$writes)->toHaveCount(1)
        ->and(HaproxyState::$writes[0]['body'])->toBe(['admin_state' => 'drain']);

    // The state the balancer reported, written onto the node, so the list an
    // operator returns to shows what they did rather than the last sweep.
    expect($backend->fresh()->attributes['state'])->toBe(BackendState::Draining->value);
});

/** And back again. */
it('puts a backend back into the rotation', function (): void {
    haproxyAnswers(['web'], ['web' => [
        ['name' => 'web-1', 'operational_state' => 'up', 'admin_state' => 'drain'],
    ]]);

    enableHaproxy($this->admin);
    discoverLoadBalancers();

    $backend = ResourceNode::query()->where('label', 'web-1')->sole();

    expect($backend->attributes['state'])->toBe(BackendState::Draining->value);

    allowHaproxyWrites();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/load-balancers/{$backend->id}/undrain", ['reason' => 'Upgrade done'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(HaproxyState::$writes[0]['body'])->toBe(['admin_state' => 'ready'])
        ->and($backend->fresh()->attributes['state'])->toBe(BackendState::Up->value);
});

/**
 * A reason a screen collects and an endpoint discards is a sentence nobody
 * reads, so the endpoint refuses without one.
 */
it('refuses a drain with no reason', function (): void {
    haproxyAnswers(['web'], ['web' => [['name' => 'web-1', 'operational_state' => 'up']]]);

    enableHaproxy($this->admin);
    discoverLoadBalancers();

    $backend = ResourceNode::query()->where('label', 'web-1')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/load-balancers/{$backend->id}/drain", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(HaproxyState::$writes)->toBe([]);
});

/**
 * The permission sits **above** `auth.recent` on the route: somebody who may
 * not drain is refused before being asked to confirm a password, which would
 * be rude and a small oracle.
 */
it('refuses somebody without the permission, without asking for a password', function (): void {
    haproxyAnswers(['web'], ['web' => [['name' => 'web-1', 'operational_state' => 'up']]]);

    enableHaproxy($this->admin);
    discoverLoadBalancers();

    $backend = ResourceNode::query()->where('label', 'web-1')->sole();
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->post("/admin/infrastructure/load-balancers/{$backend->id}/drain", ['reason' => 'No'])
        // 403, not the 302 to the password screen that a missing `auth.recent`
        // would produce.
        ->assertForbidden();

    expect(HaproxyState::$writes)->toBe([]);
});

/**
 * A capability an operator has not enabled is absent from the registry, so
 * the screen does not offer the button. This is the belt behind that.
 */
it('refuses a drain when writes are not enabled', function (): void {
    haproxyAnswers(['web'], ['web' => [['name' => 'web-1', 'operational_state' => 'up']]]);

    enableHaproxy($this->admin);
    discoverLoadBalancers();

    $backend = ResourceNode::query()->where('label', 'web-1')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/load-balancers/{$backend->id}/drain", ['reason' => 'Try it'])
        ->assertSessionHasErrors('reason');

    expect(HaproxyState::$writes)->toBe([]);
});

/** A node of another kind reached by id answers 404, never 403. */
it('answers 404 for a node that is not a backend', function (): void {
    haproxyAnswers(['web'], ['web' => [['name' => 'web-1', 'operational_state' => 'up']]]);

    enableHaproxy($this->admin);
    discoverLoadBalancers();

    $listener = ResourceNode::query()->where('kind', 'lb_listener')->sole();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post("/admin/infrastructure/load-balancers/{$listener->id}/drain", ['reason' => 'No'])
        ->assertNotFound();
});

it('drives the screen, listeners with their backends underneath', function (): void {
    haproxyAnswers(['web'], ['web' => [
        ['name' => 'web-1', 'address' => '10.0.0.1', 'port' => 80, 'operational_state' => 'up'],
        ['name' => 'web-2', 'address' => '10.0.0.2', 'port' => 80, 'operational_state' => 'up', 'admin_state' => 'drain'],
    ]]);

    enableHaproxy($this->admin);
    discoverLoadBalancers();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/load-balancers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Infrastructure/LoadBalancers')
            ->has('listeners', 1)
            ->has('listeners.0.backends', 2)
            // Two fields, as everything that crosses to the browser is.
            ->where('listeners.0.backends.0.state', BackendState::Up->value)
            ->where('listeners.0.backends.0.stateTone', 'healthy')
            ->where('listeners.0.backends.0.serving', true)
            // Draining is neither up nor down, and it is somebody's decision.
            ->where('listeners.0.backends.1.stateTone', 'maintenance')
            ->where('listeners.0.backends.1.serving', false)
            ->where('can.drain', true));
});

it('refuses the screen to somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/infrastructure/load-balancers')
        ->assertForbidden();
});

/** Every state an operator reads is named, in both languages. */
it('names every backend state in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (BackendState::cases() as $state) {
            expect(__($state->labelKey()))->not->toBe($state->labelKey());
        }
    }

    app()->setLocale('en');
});
