<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\PlacementStrategy;
use App\Domain\Provisioning\ServerStatus;
use App\Domain\Provisioning\ServiceStatus;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Jobs\CheckServerHealth;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * The fleet screen, with every button pressed.
 *
 * It exists because of a bug that had been shipped and could not be seen:
 * the screen moved behind the Apps door in a later phase, its routes became
 * `/admin/apps/infrastructure/…`, and the page went on posting to
 * `/admin/infrastructure/…`. Adding a server, editing one, deleting one,
 * adding a group and testing a connection all answered 404 and looked like
 * nothing happening.
 *
 * Nothing caught it. The screen had a feature test and it **rendered** the
 * screen; Phase 9's lesson was that a screen with no test that renders it has
 * not been tested, and this is the next one along — **a screen whose actions
 * no test performs has not been tested either.** So this file drives them
 * rather than asserting the page exists.
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

    $this->owner = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();

    $this->administrator = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->administrator->assignRole(SystemRole::Administrator);
    $this->administrator = $this->administrator->fresh();

    /*
     * Deleting a server asks for a password again (Phase 17): the row holds
     * credentials to somebody else's machine. These tests are about what the
     * screen *does*, so the confirmation is granted here once — the guard
     * itself is the subject of its own case at the foot of this file.
     */
    $this->withSession([RequireRecentAuthentication::SESSION_KEY => time()]);
});

/**
 * @return array<string, mixed>
 */
function serverPayload(array $overrides = []): array
{
    return [
        'name' => 'node-01',
        'server_group_id' => null,
        'module' => 'manual',
        'hostname' => 'node-01.example.test',
        'ip_address' => '198.51.100.10',
        'port' => 2087,
        'secure' => true,
        'username' => 'root',
        'secret' => 'a-token-from-the-panel',
        'status' => ServerStatus::Active->value,
        'region' => 'eu-west',
        'max_services' => 200,
        'weight' => 1,
        'nameservers' => 'ns1.example.test,ns2.example.test',
        ...$overrides,
    ];
}

it('adds a group from the screen', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/infrastructure/groups', [
            'name' => 'Amsterdam',
            'placement_strategy' => PlacementStrategy::LeastAccounts->value,
            'region' => 'eu-west',
            'notes' => 'The first rack.',
        ])
        ->assertRedirect();

    $group = ServerGroup::query()->where('name', 'Amsterdam')->first();

    expect($group)->not->toBeNull()
        ->and($group->organization_id)->toBe($this->provider->id)
        // The slug is derived rather than asked for: one fewer field on a form,
        // and it cannot collide with another operator's.
        ->and($group->slug)->toStartWith('amsterdam-');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'provisioning.server_group.created',
        'target_id' => $group->id,
    ]);
});

it('edits and deletes a group from the screen', function (): void {
    $group = ServerGroup::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->owner, 'staff')
        ->put('/admin/apps/infrastructure/groups/'.$group->id, [
            'name' => 'Rotterdam',
            'placement_strategy' => PlacementStrategy::Weighted->value,
            'region' => null,
            'notes' => null,
        ])
        ->assertRedirect();

    expect($group->fresh()->name)->toBe('Rotterdam')
        ->and($group->fresh()->placement_strategy)->toBe(PlacementStrategy::Weighted);

    $this->actingAs($this->owner, 'staff')
        ->delete('/admin/apps/infrastructure/groups/'.$group->id)
        ->assertRedirect();

    expect(ServerGroup::query()->whereKey($group->id)->exists())->toBeFalse();
});

it('adds a server from the screen, and never sends the token back', function (): void {
    $group = ServerGroup::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/infrastructure/servers', serverPayload([
            'server_group_id' => $group->id,
        ]))
        ->assertRedirect();

    $server = Server::query()->where('name', 'node-01')->first();

    expect($server)->not->toBeNull()
        ->and($server->hostname)->toBe('node-01.example.test')
        ->and($server->server_group_id)->toBe($group->id)
        ->and($server->max_services)->toBe(200)
        // Stored, and stored encrypted — the column is not the plain token.
        ->and($server->secret)->toBe('a-token-from-the-panel')
        ->and($server->getRawOriginal('secret'))->not->toBe('a-token-from-the-panel');

    // The audit row an operator reads must not carry the credential either.
    $audit = $this->getConnection()->table('audit_logs')
        ->where('action', 'provisioning.server.created')
        ->first();

    expect($audit)->not->toBeNull()
        ->and(json_encode($audit))->not->toContain('a-token-from-the-panel');

    // And the screen that lists it says whether there is a secret, never what
    // it is.
    $body = $this->actingAs($this->owner, 'staff')->get('/admin/apps/infrastructure')->getContent();

    expect($body)->not->toContain('a-token-from-the-panel');
});

it('keeps the stored token when the field is left empty on an edit', function (): void {
    $server = Server::factory()->create([
        'organization_id' => $this->provider->id,
        'secret' => 'the-original-token',
    ]);

    $this->actingAs($this->owner, 'staff')
        ->put('/admin/apps/infrastructure/servers/'.$server->id, serverPayload([
            'name' => 'node-renamed',
            'secret' => '',
        ]))
        ->assertRedirect();

    // The screen never pre-fills a secret, so an edit that changes a name would
    // otherwise erase the credential and take the fleet with it.
    expect($server->fresh()->name)->toBe('node-renamed')
        ->and($server->fresh()->secret)->toBe('the-original-token');
});

it('refuses to delete a server that still carries services', function (): void {
    $server = Server::factory()->create(['organization_id' => $this->provider->id]);

    Service::factory()->create([
        'organization_id' => $this->provider->id,
        'server_id' => $server->id,
        'status' => ServiceStatus::Active->value,
    ]);

    $this->actingAs($this->owner, 'staff')
        ->delete('/admin/apps/infrastructure/servers/'.$server->id)
        ->assertRedirect();

    // Deleting it would orphan running accounts nobody could find again.
    expect(Server::query()->whereKey($server->id)->exists())->toBeTrue();

    $empty = Server::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->owner, 'staff')
        ->delete('/admin/apps/infrastructure/servers/'.$empty->id)
        ->assertRedirect();

    expect(Server::query()->whereKey($empty->id)->exists())->toBeFalse();
});

it('queues a health check rather than talking to the panel in the request', function (): void {
    Queue::fake();

    $server = Server::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/infrastructure/servers/'.$server->id.'/test')
        ->assertRedirect();

    Queue::assertPushed(CheckServerHealth::class);
});

it('refuses every write to an administrator who is not the owner', function (): void {
    $group = ServerGroup::factory()->create(['organization_id' => $this->provider->id]);
    $server = Server::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->administrator, 'staff')
        ->post('/admin/apps/infrastructure/groups', [
            'name' => 'Nope',
            'placement_strategy' => PlacementStrategy::LeastAccounts->value,
        ])
        ->assertForbidden();

    $this->actingAs($this->administrator, 'staff')
        ->post('/admin/apps/infrastructure/servers', serverPayload())
        ->assertForbidden();

    $this->actingAs($this->administrator, 'staff')
        ->delete('/admin/apps/infrastructure/servers/'.$server->id)
        ->assertForbidden();

    $this->actingAs($this->administrator, 'staff')
        ->delete('/admin/apps/infrastructure/groups/'.$group->id)
        ->assertForbidden();

    expect(Server::query()->whereKey($server->id)->exists())->toBeTrue()
        ->and(ServerGroup::query()->whereKey($group->id)->exists())->toBeTrue();
});

/**
 * The two guards on the delete, in the order they have to run.
 *
 * `owner` is on the route group and `auth.recent` is on the route, so somebody
 * who may not touch the fleet is refused **before** being asked for a
 * password. Getting that backwards is rude and a small oracle, and it is the
 * mistake Phase 17 made once on the Licence screen.
 */
it('asks the owner for a password before deleting a server, and refuses everybody else first', function (): void {
    $server = Server::factory()
        ->create(['organization_id' => $this->provider->id]);

    $this->flushSession();

    $this->actingAs($this->owner, 'staff')
        ->delete('/admin/apps/infrastructure/servers/'.$server->id)
        ->assertRedirect('/admin/confirm-password');

    expect(Server::query()->whereKey($server->id)->exists())->toBeTrue();

    // An administrator is refused outright rather than asked for a password
    // they were never going to be allowed to use.
    $this->actingAs($this->administrator, 'staff')
        ->delete('/admin/apps/infrastructure/servers/'.$server->id)
        ->assertForbidden();
});

it('refuses a server whose form is wrong, without touching the fleet', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/infrastructure/servers', serverPayload([
            'name' => '',
            'port' => 99_999,
            'ip_address' => 'not-an-address',
        ]))
        ->assertSessionHasErrors(['name', 'port', 'ip_address']);

    expect(Server::query()->count())->toBe(0);
});
