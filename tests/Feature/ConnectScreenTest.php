<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Connect, and who may use it.
 *
 * The screen exists so that nobody has to be handed a root password. That is
 * only true if the people who need to get into a panel can — so it is a
 * permission (`infrastructure.connect`) rather than the owner-only gate, and
 * the support role holds it.
 *
 * What the screen must never do is leak the thing it exists to avoid handing
 * over. Two of the cases below are about that: the stored token reaches
 * neither the page nor anything else the browser receives, and a server the
 * acting organization does not own is not on the list at all.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->agent = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->agent->assignRole(SystemRole::Support);
    $this->agent = $this->agent->fresh();

    $this->server = Server::factory()->create([
        'organization_id' => $this->provider->id,
        'name' => 'node-amsterdam',
        'secret' => 'the-panel-api-token',
    ]);
});

it('opens to a support agent, because that is the point of it', function (): void {
    $this->actingAs($this->agent, 'staff')
        ->get('/admin/apps/connect')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Apps/Connect')
            ->has('servers', 1)
            ->where('servers.0.name', 'node-amsterdam')
            // Whether there is a credential, never what it is.
            ->where('servers.0.hasSecret', true));
});

it('never sends the stored token to the browser', function (): void {
    $body = $this->actingAs($this->agent, 'staff')
        ->get('/admin/apps/connect')
        ->getContent();

    // The whole argument for this screen is that an operator is given a way in
    // rather than a credential. A token in the page source would make it a
    // worse way of handing over the credential, not a better one.
    expect($body)->not->toContain('the-panel-api-token');
});

it('refuses somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($nobody, 'staff')
        ->get('/admin/apps/connect')
        ->assertForbidden();

    $this->actingAs($nobody, 'staff')
        ->post('/admin/apps/connect/servers/'.$this->server->id.'/session')
        ->assertForbidden();
});

it('lists only the servers the acting organization owns', function (): void {
    $other = Organization::factory()->create([
        'type' => OrganizationType::Provider->value,
        'parent_id' => null,
    ]);

    app(OrganizationContext::class)->withoutBoundary(fn (): Server => Server::factory()->create([
        'organization_id' => $other->id,
        'name' => 'somebody-elses-node',
    ]));

    $this->actingAs($this->agent, 'staff')
        ->get('/admin/apps/connect')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('servers', 1)
            ->where('servers.0.name', 'node-amsterdam'));
});

it('answers 404 for a server outside the boundary rather than saying it exists', function (): void {
    $other = Organization::factory()->create([
        'type' => OrganizationType::Provider->value,
        'parent_id' => null,
    ]);

    $theirs = app(OrganizationContext::class)->withoutBoundary(
        fn (): Server => Server::factory()->create(['organization_id' => $other->id]),
    );

    $this->actingAs($this->agent, 'staff')
        ->post('/admin/apps/connect/servers/'.$theirs->id.'/session')
        ->assertNotFound();
});
