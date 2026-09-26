<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Infrastructure\RecordSamples;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\RawSample;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Infrastructure\SampleBatch;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServerStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceMetricDayFactory;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * The three Infrastructure screens, rendered.
 *
 * Phase 9's lesson is the reason this file exists at all: a screen with no
 * feature test that renders it has not been tested, and a `static fn` that cannot
 * reach `$this` passes every test written against the classes behind it.
 *
 * At least two of everything, always. Strict mode only reports a lazy load when a
 * query returned more than one row, so a screen that is correct with one record
 * and throws with two passes a single-fixture test and fails the first time an
 * operator opens it.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    $this->admin = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    app(OrganizationContext::class)->set($this->provider->id);

    $graph = app(ResourceGraph::class);

    $this->rack = $graph->upsertNode($this->provider->id, ResourceKind::Organization, 'org', 'Provider');
    $this->one = $graph->upsertNode($this->provider->id, ResourceKind::Server, 'node-1', 'Node one');
    $this->two = $graph->upsertNode($this->provider->id, ResourceKind::Server, 'node-2', 'Node two');

    $graph->attach($this->rack, $this->one, Relation::Contains);
    $graph->attach($this->rack, $this->two, Relation::Contains);

    /*
     * Phase 17's guard applies to `infrastructure.adapters.manage`, which is
     * declared high risk. These tests are about what the screens do, so the
     * confirmation is granted here once — the guard itself is
     * `SecurityHardeningTest`'s subject rather than a precondition.
     */
    $this->withSession([RequireRecentAuthentication::SESSION_KEY => time()]);
});

it('renders the explorer with both resources and the counts above them', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/resources')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Resources/Explorer')
            ->has('nodes.data', 3)
            ->where('stats.nodes', 3)
            // Nothing has reported on any of them yet, which is the count this
            // screen exists to surface rather than hide.
            ->where('stats.unwatched', 3)
            ->has('kinds')
            ->has('healthStates'));
});

it('builds the drawer only when it is asked for by name', function (): void {
    $version = app(HandleInertiaRequests::class)->version(request());

    // A partial reload renders no page object, so `assertInertia` cannot read
    // the answer: the props are asserted as JSON instead (the Phase 11 lesson).
    $this->actingAs($this->admin, 'staff')
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Admin/Resources/Explorer',
            'X-Inertia-Partial-Data' => 'peek',
        ])
        ->get('/admin/resources?node='.$this->one->id)
        ->assertOk()
        ->assertJsonPath('props.peek.node.label', 'Node one')
        ->assertJsonPath('props.peek.impact.services', 0)
        ->assertJsonMissingPath('props.nodes');
});

/**
 * §3 asks for capacity and maintenance beside the readings on a resource's own
 * view. Both are drawn from something other than the graph row, so both are
 * facts a drawer can silently stop carrying.
 */
it('carries capacity and the operators own word into the drawer', function (): void {
    $server = Server::factory()->create([
        'organization_id' => $this->provider->id,
        'status' => ServerStatus::Maintenance->value,
    ]);

    $node = app(ResourceGraph::class)->upsertNode(
        $this->provider->id,
        ResourceKind::Server,
        $server->id,
        $server->name,
        subject: $server,
    );

    // Eight days of a disk climbing towards a ceiling the adapter reported.
    foreach (range(0, 7) as $index) {
        ResourceMetricDayFactory::new()
            ->forNode($node)
            ->on(CarbonImmutable::now()->subDays(8 - $index)->toDateString(), 400.0 + ($index * 20))
            ->create(['metric' => 'disk.used', 'unit' => 'bytes']);
    }

    app(RecordSamples::class)->handle($this->provider->id, 'probe', new SampleBatch([
        new RawSample($server->id, 'disk_used', 540, 'bytes'),
        new RawSample($server->id, 'disk_total', 1000, 'bytes'),
    ]));

    $version = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($this->admin, 'staff')
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Admin/Resources/Explorer',
            'X-Inertia-Partial-Data' => 'peek',
        ])
        ->get('/admin/resources?node='.$node->id)
        ->assertOk()
        ->assertJsonPath('props.peek.capacity.0.metric', 'disk.used')
        ->assertJsonPath('props.peek.capacity.0.filling', true)
        // A node in maintenance has an `unknown` health because nothing is
        // checking a box that was taken out of service on purpose. Without this
        // the drawer would read as a monitoring gap.
        ->assertJsonPath('props.peek.operatorState.state', ServerStatus::Maintenance->value)
        ->assertJsonPath(
            'props.peek.operatorState.stateLabel',
            __(ServerStatus::Maintenance->labelKey()),
        );
});

it('does not open the explorer to a role without the permission', function (): void {
    $support = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $support->assignRole(SystemRole::Support);

    // Support *does* hold it — a support agent mid-ticket needs to know what a
    // service sits on — so the refusal has to be tested with a role that does not.
    $this->actingAs($support->fresh(), 'staff')->get('/admin/resources')->assertOk();

    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($nobody, 'staff')->get('/admin/resources')->assertForbidden();
});

it('renders telemetry with what arrived and what has not', function (): void {
    app(RecordSamples::class)->handle($this->provider->id, 'probe', new SampleBatch([
        new RawSample('node-1', 'cpu_percent', 40, 'percent'),
        new RawSample('node-1', 'memory_used', 8, 'gigabytes'),
    ]));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/resources/telemetry')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Resources/Telemetry')
            ->has('metrics.data', 2)
            ->where('stats.measurements', 2)
            ->where('stats.sources', 1)
            // Two nodes have nothing reporting on them, and the screen says so.
            ->where('stats.unwatched', 2)
            ->has('unwatched', 2));
});

it('renders the adapters screen with nothing on it when no module provides one', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/resources/adapters')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Resources/Adapters')
            ->has('adapters', 0));
});

it('allows writes only deliberately, and writes down who allowed what', function (): void {
    $adapter = ResourceAdapter::factory()
        ->declaring([Capability::MetricsRead, Capability::FirewallPolicyWrite])
        ->create(['organization_id' => $this->provider->id]);

    expect($adapter->permittedCapabilities()->has(Capability::FirewallPolicyWrite))->toBeFalse();

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/resources/adapters/'.$adapter->id.'/writes', [
            'writes_enabled' => true,
            'reason' => 'Change window CHG-41',
        ])
        ->assertRedirect();

    expect($adapter->fresh()->writes_enabled)->toBeTrue()
        ->and($adapter->fresh()->permittedCapabilities()->has(Capability::FirewallPolicyWrite))->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'infrastructure.adapter.writes_allowed',
        'target_id' => $adapter->id,
    ]);
});

it('refuses to allow writes to somebody who may only look', function (): void {
    $adapter = ResourceAdapter::factory()
        ->declaring([Capability::MetricsRead, Capability::FirewallPolicyWrite])
        ->create(['organization_id' => $this->provider->id]);

    $support = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->put('/admin/resources/adapters/'.$adapter->id.'/writes', ['writes_enabled' => true])
        ->assertForbidden();

    expect($adapter->fresh()->writes_enabled)->toBeFalse();
});

/**
 * Switching an adapter off is what somebody does when an incident is already
 * under way. It is the one thing here that is deliberately not behind the
 * password challenge, so that is pinned rather than left to a route file.
 */
it('lets an adapter be switched off without asking for a password again', function (): void {
    $adapter = ResourceAdapter::factory()
        ->declaring([Capability::MetricsRead])
        ->create(['organization_id' => $this->provider->id]);

    $this->flushSession();

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/resources/adapters/'.$adapter->id, ['enabled' => false])
        ->assertRedirect();

    expect($adapter->fresh()->enabled)->toBeFalse();

    // And allowing writes from the same session is still stopped.
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/resources/adapters/'.$adapter->id.'/writes', ['writes_enabled' => true])
        ->assertRedirect('/admin/confirm-password');

    expect($adapter->fresh()->writes_enabled)->toBeFalse();
});

it('narrows an adapter to its reads while writes are off', function (): void {
    $adapter = ResourceAdapter::factory()
        ->declaring([Capability::MetricsRead, Capability::BmcPowerWrite])
        ->create(['organization_id' => $this->provider->id]);

    $permitted = $adapter->permittedCapabilities();

    // Absent rather than refused later: a screen built from this cannot offer a
    // button the platform would then refuse.
    expect($permitted->has(Capability::MetricsRead))->toBeTrue()
        ->and($permitted->writes())->toBeEmpty();

    $adapter->writes_enabled = true;
    $adapter->enabled = false;
    $adapter->save();

    // Disabled beats allowed. An operator switching an adapter off during an
    // incident must not have to also revoke its writes.
    expect($adapter->permittedCapabilities()->all())->toBeEmpty();
});
