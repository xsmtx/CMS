<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * An adapter's credential, written from the screen that owns the adapter.
 *
 * This is the vault's first door (`advanced-operations-plan.md` §7), and the
 * rule it follows is the licence key's: the screen can say whether one is
 * stored and when it last changed, and it can never say what it is. The
 * assertions that matter are the ones about what does *not* appear — in the
 * props, in the rendered page, in the audit row.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->withoutGlobalScope('organization')
        ->whereNull('parent_id')
        ->sole();

    $this->admin = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->adapter = ResourceAdapter::query()->create([
        'organization_id' => $this->provider->id,
        'adapter_key' => 'prometheus',
        'name' => 'Prometheus',
        'vendor' => 'Prometheus',
        'module' => 'monitoring-prometheus',
        'enabled' => true,
    ]);

    // A direct call to the vault in a test has no request to take a boundary
    // from, which is the refusal `MissingSecretBoundary` exists for.
    app(OrganizationContext::class)->set($this->provider->id);

    // The screen is behind the recent-password challenge, like the writes
    // switch: a credential is how an installation reaches somebody else's
    // system.
    $this->session([RequireRecentAuthentication::SESSION_KEY => now()->timestamp]);
});

it('stores a credential in the vault rather than on the row', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->put("/admin/resources/adapters/{$this->adapter->id}/credential", ['value' => 'a-secret-token'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $reference = new SecretReference('monitoring', 'token', 'prometheus');

    expect(app(SecretStore::class)->get($reference))->toBe('a-secret-token');

    // Not on the adapter row, and not in the clear anywhere.
    expect(json_encode($this->adapter->fresh()?->toArray()))->not->toContain('a-secret-token')
        ->and(DB::table('secrets')->where('reference', $reference->key())->value('value'))
        ->not->toContain('a-secret-token');
});

it('destroys the credential when the field is sent empty', function (): void {
    $reference = new SecretReference('monitoring', 'token', 'prometheus');
    app(SecretStore::class)->put($reference, 'a-secret-token');

    $this->actingAs($this->admin, 'staff')
        ->put("/admin/resources/adapters/{$this->adapter->id}/credential", ['value' => ''])
        ->assertRedirect();

    expect(app(SecretStore::class)->has($reference))->toBeFalse();
});

/**
 * The screen says whether there is one. If it ever says more than that, this
 * is the test that fails.
 */
it('tells the screen that a credential exists and nothing else about it', function (): void {
    app(SecretStore::class)->put(new SecretReference('monitoring', 'token', 'prometheus'), 'a-secret-token');

    $response = $this->actingAs($this->admin, 'staff')->get('/admin/resources/adapters');

    $response->assertOk();

    expect($response->getContent())->not->toContain('a-secret-token');
});

it('refuses somebody who may not manage adapters', function (): void {
    $support = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->put("/admin/resources/adapters/{$this->adapter->id}/credential", ['value' => 'nope'])
        ->assertForbidden();

    expect(app(SecretStore::class)->has(new SecretReference('monitoring', 'token', 'prometheus')))
        ->toBeFalse();
});

/**
 * `owner` runs before `auth.recent` everywhere else in this product for a
 * reason; here the guard is the permission, and the challenge is what stands
 * between a stolen session and somebody else's monitoring system.
 */
it('asks for the password again before it will take one', function (): void {
    $this->session([RequireRecentAuthentication::SESSION_KEY => now()->subHours(2)->timestamp]);

    $this->actingAs($this->admin, 'staff')
        ->put("/admin/resources/adapters/{$this->adapter->id}/credential", ['value' => 'a-secret-token'])
        ->assertRedirect();

    expect(app(SecretStore::class)->has(new SecretReference('monitoring', 'token', 'prometheus')))
        ->toBeFalse();
});
