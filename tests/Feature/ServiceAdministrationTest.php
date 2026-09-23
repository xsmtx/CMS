<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Provisioning\ServerStatus;
use App\Domain\Provisioning\ServiceOperation;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Jobs\CheckServerHealth;
use App\Infrastructure\Provisioning\Jobs\ProvisionService;
use App\Infrastructure\Provisioning\Jobs\RunServiceAction;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\ModuleRegistry;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeProvisioningModule;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $registry = new ModuleRegistry;
    $registry->register(new FakeProvisioningModule);
    $this->app->instance(ModuleRegistry::class, $registry);

    $this->operator = StaffUser::factory()->create();
    $this->operator->assignRole(SystemRole::Administrator);
    $this->operator = $this->operator->fresh();

    // Servers live behind Apps and Integrations now, which is open to the
    // owner of the installation only: adding one hands out credentials to
    // somebody else's machine.
    $this->owner = StaffUser::factory()->create();
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();

    $this->group = ServerGroup::factory()->create();
    $this->server = Server::factory()->inGroup($this->group)->create(['module' => 'fake']);

    $this->service = Service::factory()->on($this->server)->create(['module' => 'fake']);
});

it('lists services with the counts an operator opens the screen for', function (): void {
    Service::factory()->status(ServiceStatus::Failed)->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Services/Index')
            ->has('services.data', 2)
            ->where('counts.failed', 1)
            ->where('counts.pending', 1));
});

it('shows a service with only the actions its module supports', function (): void {
    $this->actingAs($this->operator, 'staff')
        ->get("/admin/services/{$this->service->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Services/Show')
            ->where('service.module', 'fake')
            ->where('can.provision', true)
            ->where('can.terminate', true));
});

it('offers nothing to run when the module does not exist on this installation', function (): void {
    $orphan = Service::factory()->create(['module' => 'gone']);

    $this->actingAs($this->operator, 'staff')
        ->get("/admin/services/{$orphan->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.suspend', false)
            ->where('can.terminate', false));
});

it('queues a setup rather than running it in the request', function (): void {
    Queue::fake();

    $this->actingAs($this->operator, 'staff')
        ->post("/admin/services/{$this->service->id}/provision")
        ->assertRedirect();

    // A control panel that takes forty seconds would otherwise hold a web
    // worker while an operator refreshes and sends a second one.
    Queue::assertPushed(ProvisionService::class);
});

it('queues a suspend with the reason an operator typed', function (): void {
    Queue::fake();

    $this->service->forceFill(['status' => ServiceStatus::Active->value])->save();

    $this->actingAs($this->operator, 'staff')
        ->post("/admin/services/{$this->service->id}/actions", [
            'operation' => 'suspend',
            'reason' => 'Non-payment',
        ])
        ->assertRedirect();

    Queue::assertPushed(
        RunServiceAction::class,
        fn (RunServiceAction $job): bool => $job->operation === ServiceOperation::Suspend
            && $job->reason === 'Non-payment',
    );
});

/**
 * The danger zone's action (§8), and the reason that makes it accountable.
 *
 * Terminate used to sit in the same row as Suspend and Sync, which is a
 * button muscle memory reaches on a Friday afternoon. It is now at the foot
 * of the page behind a confirmation that wants the service's own name typed
 * out — and the reason it collects reaches the job, which is what somebody
 * will look for when the customer asks why their account is gone.
 */
it('queues a termination with the reason the danger zone collected', function (): void {
    Queue::fake();

    $this->service->forceFill(['status' => ServiceStatus::Active->value])->save();

    $this->actingAs($this->operator, 'staff')
        ->post("/admin/services/{$this->service->id}/actions", [
            'operation' => 'terminate',
            'reason' => 'Closed the account at their request',
        ])
        ->assertRedirect();

    Queue::assertPushed(
        RunServiceAction::class,
        fn (RunServiceAction $job): bool => $job->operation === ServiceOperation::Terminate
            && $job->reason === 'Closed the account at their request',
    );
});

it('refuses an operation the route does not accept', function (): void {
    $this->actingAs($this->operator, 'staff')
        ->post("/admin/services/{$this->service->id}/actions", ['operation' => 'create'])
        ->assertSessionHasErrors('operation');
});

it('hands over the provider password only when asked, and records it', function (): void {
    $this->service->forceFill([
        'username' => 'bobhost',
        'password' => 'provider-issued',
    ])->save();

    // Not a field on the page: reading somebody's control panel password is
    // an action, not a side effect of opening a screen.
    $this->actingAs($this->operator, 'staff')
        ->get("/admin/services/{$this->service->id}")
        ->assertOk()
        ->assertDontSee('provider-issued');

    $this->actingAs($this->operator, 'staff')
        ->post("/admin/services/{$this->service->id}/credentials")
        ->assertRedirect()
        ->assertSessionHas('credentials');
});

it('refuses a service screen to staff without the permission', function (): void {
    $support = StaffUser::factory()->create();
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->get('/admin/services')
        ->assertForbidden();
});

it('lists the fleet without ever sending a token to the browser', function (): void {
    $this->server->forceFill(['secret' => 'whm-token-value'])->save();

    $this->actingAs($this->owner, 'staff')
        ->get('/admin/apps/infrastructure')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Infrastructure/Index')
            ->has('servers', 1)
            // The fact that one is set, never the value.
            ->where('servers.0.hasSecret', true)
            ->missing('servers.0.secret'))
        ->assertDontSee('whm-token-value');
});

it('keeps the stored token when an operator saves the form without one', function (): void {
    $this->server->forceFill(['secret' => 'original-token'])->save();

    $this->actingAs($this->owner, 'staff')
        ->put("/admin/apps/infrastructure/servers/{$this->server->id}", [
            'name' => 'Renamed node',
            'module' => 'fake',
            'hostname' => 'node.test',
            'port' => 2087,
            'username' => 'root',
            'secret' => '',
            'status' => ServerStatus::Active->value,
            'max_services' => 50,
            'weight' => 1,
        ])
        ->assertRedirect();

    expect($this->server->fresh()?->name)->toBe('Renamed node')
        ->and($this->server->fresh()?->secret)->toBe('original-token');
});

it('refuses to delete a server that still holds services', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->delete("/admin/apps/infrastructure/servers/{$this->server->id}")
        ->assertRedirect();

    // Deleting it would orphan running accounts nobody could find again.
    expect(Server::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

it('queues a health check rather than blocking on a control panel', function (): void {
    Queue::fake();

    $this->actingAs($this->owner, 'staff')
        ->post("/admin/apps/infrastructure/servers/{$this->server->id}/test")
        ->assertRedirect();

    Queue::assertPushed(CheckServerHealth::class);
});

it('records what a health check found', function (): void {
    dispatch_sync(new CheckServerHealth($this->server->id));

    expect($this->server->fresh()?->health)->toBe('healthy')
        ->and($this->server->fresh()?->health_checked_at)->not->toBeNull();
});

/**
 * The behaviour change this move makes, pinned so nobody restores it by
 * accident: an administrator runs the business, and the owner of the
 * installation decides which machines it talks to.
 */
it('keeps the fleet behind the Apps door', function (): void {
    $this->actingAs($this->operator, 'staff')
        ->get('/admin/apps/infrastructure')
        ->assertForbidden();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/apps')
        ->assertForbidden();

    $this->actingAs($this->owner, 'staff')
        ->get('/admin/apps')
        ->assertOk();
});
