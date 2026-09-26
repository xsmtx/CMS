<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Network\AccessGrants;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Network\Exceptions\GrantRefused;
use App\Domain\Network\GrantableCapability;
use App\Domain\Organizations\OrganizationType;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\AccessGrant;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Collection;

/**
 * Just-in-time access (`phase-c-plan.md` §17).
 *
 * A grant is somebody holding one more thing than usual, until a time. Three
 * things about it are the design rather than the implementation, and each has
 * a test here:
 *
 * **Whether it is live is a question about two timestamps**, never a state
 * column and never "has the sweep run". A scheduler that was down for three
 * hours must leave nobody holding access they should not have.
 *
 * **Nobody grants themselves anything**, which a permission cannot express:
 * a permission says who may grant and cannot say to whom.
 *
 * **A grant only ever adds.** Somebody who already holds the permission is
 * unaffected, which is what makes asking the gate everywhere safe.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create(['name' => 'Gave it']);
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    // Somebody with a role that holds nothing: the person a grant is for.
    $this->outsider = StaffUser::factory()->create(['name' => 'Got it']);
    $this->outsider->assignRole(Role::query()->create([
        'name' => 'Nothing',
        'slug' => 'nothing',
        'scope' => RoleScope::Staff,
    ]));
    $this->outsider = $this->outsider->fresh();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function grantConnect(int $minutes = 120): AccessGrant
{
    return app(AccessGrants::class)->grant(
        holder: test()->outsider,
        capability: GrantableCapability::Connect,
        granter: test()->admin,
        reason: 'Ticket 2291: the customer cannot reach their mailbox.',
        expiresAt: CarbonImmutable::now()->addMinutes($minutes),
    );
}

it('opens a door that was shut, and closes it again by itself', function (): void {
    // Shut: the role holds nothing.
    $this->actingAs($this->outsider, 'staff')->get('/admin/apps/connect')->assertForbidden();

    grantConnect(minutes: 60);

    $this->actingAs($this->outsider->fresh(), 'staff')->get('/admin/apps/connect')->assertOk();

    // An hour and a minute later, without anything having run: the gate asks
    // the grant's own timestamps, so a scheduler that was down leaves nobody
    // holding access they should not have.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(61));

    $this->actingAs($this->outsider->fresh(), 'staff')->get('/admin/apps/connect')->assertForbidden();
});

it('refuses a grant to the person giving it', function (): void {
    expect(fn () => app(AccessGrants::class)->grant(
        holder: $this->admin,
        capability: GrantableCapability::Connect,
        granter: $this->admin,
        reason: 'Because I said so.',
        expiresAt: CarbonImmutable::now()->addHour(),
    ))->toThrow(GrantRefused::class, 'other than');
});

/**
 * Both ends are bounded: a grant of one minute is a grant somebody is about
 * to give again, and one of a fortnight is a permission with extra steps.
 */
it('refuses a window that is silly at either end', function (): void {
    expect(fn (): AccessGrant => grantConnect(minutes: 2))->toThrow(GrantRefused::class, 'five minutes');
    expect(fn (): AccessGrant => grantConnect(minutes: 60 * 24 * 14))->toThrow(GrantRefused::class, 'at most');
});

it('writes down that a grant has run out, without anything depending on it', function (): void {
    $grant = grantConnect(minutes: 30);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));

    // Already false before the sweep. The sweep is the record, not the rule.
    expect(app(AccessGrants::class)->allows($this->outsider, GrantableCapability::Connect))
        ->toBeFalse();

    $summary = app(TaskRegistry::class)->resolve(AutomationTask::AccessGrants)->handle();

    expect($summary->changed)->toBe(1)
        ->and($grant->fresh()?->revoked_at)->not->toBeNull()
        ->and($grant->fresh()?->revocation_reason)->toBe('expired')
        // No actor: an expiry has none, and a record whose author was
        // invented would be a record that lied about who acted.
        ->and($grant->fresh()?->revoked_by)->toBeNull();
});

/** Run it twice and the second changes nothing — the rule every task follows. */
it('changes nothing the second time', function (): void {
    grantConnect(minutes: 30);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));

    app(TaskRegistry::class)->resolve(AutomationTask::AccessGrants)->handle();
    $second = app(TaskRegistry::class)->resolve(AutomationTask::AccessGrants)->handle();

    expect($second->changed)->toBe(0)
        ->and($second->examined)->toBe(0);
});

it('grants and revokes from the Connect screen', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post('/admin/apps/connect/grants', [
            'staff' => $this->outsider->id,
            'capability' => GrantableCapability::Connect->value,
            'minutes' => 90,
            'reason' => 'Ticket 2291.',
        ])
        // `assertSessionHasNoErrors` alone passes against a 403 and a 404.
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $grant = AccessGrant::query()->sole();

    expect($grant->staff_user_id)->toBe($this->outsider->id)
        ->and($grant->granted_by)->toBe($this->admin->id)
        ->and($grant->isLive())->toBeTrue();

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/apps/connect/grants/'.$grant->id)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($grant->fresh()?->isLive())->toBeFalse()
        ->and($grant->fresh()?->revoked_by)->toBe($this->admin->id);
});

/**
 * The permission sits above `auth.recent` on the route, so somebody who may
 * not grant is refused rather than asked to confirm a password and then
 * refused. Rude, and a small oracle.
 */
it('refuses somebody without the permission before asking for a password', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->post('/admin/apps/connect/grants', [
            'staff' => $this->outsider->id,
            'capability' => GrantableCapability::Connect->value,
            'minutes' => 90,
            'reason' => 'Let me in.',
        ])
        ->assertForbidden();

    expect(AccessGrant::query()->count())->toBe(0);
});

/**
 * Nobody grants themselves anything, and the screen must not offer it either
 * — a select that lists somebody the server will refuse is a form that fails
 * after being filled in.
 */
it('leaves the person asking out of the list', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/apps/connect')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.grant', true)
            ->has('grantable', 1)
            ->where('staff', fn (Collection $staff): bool => $staff
                ->doesntContain(fn (array $row): bool => $row['value'] === test()->admin->id))
            ->where('staff', fn (Collection $staff): bool => $staff
                ->contains(fn (array $row): bool => $row['value'] === test()->outsider->id)));
});
