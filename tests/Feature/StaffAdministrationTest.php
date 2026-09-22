<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\AccountStatus;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()->withoutGlobalScope('organization')
        ->where('type', 'provider')->sole();

    $this->admin = StaffUser::factory()->forOrganization($this->provider)->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

it('refuses the staff list without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin/staff')
        ->assertForbidden();
});

it('lists staff for someone with the permission', function (): void {
    StaffUser::factory()->forOrganization($this->provider)->count(2)->create();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/staff')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Staff/Index')
            ->has('staff.data', 3));
});

it('never lists another reseller staff', function (): void {
    $otherReseller = Organization::factory()->reseller($this->provider)->create();
    StaffUser::factory()->forOrganization($otherReseller)->create(['name' => 'Their Admin']);

    $reseller = Organization::factory()->reseller($this->provider)->create();
    $resellerAdmin = StaffUser::factory()->forOrganization($reseller)->create();
    $resellerAdmin->assignRole(SystemRole::Administrator);

    $this->actingAs($resellerAdmin->fresh(), 'staff')
        ->get('/admin/staff')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('staff.data', 1)
            ->where('staff.data.0.id', $resellerAdmin->id));
});

it('creates a staff account without ever setting a known password', function (): void {
    $role = Role::query()->where('slug', SystemRole::Support->value)->sole();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/staff', [
            'name' => 'Nadia Okonkwo',
            'email' => 'nadia@example.test',
            'status' => AccountStatus::Active->value,
            'role_ids' => [$role->id],
        ])
        ->assertRedirect('/admin/staff');

    $created = StaffUser::query()->where('email', 'nadia@example.test')->sole();

    expect($created->organization_id)->toBe($this->provider->id)
        ->and($created->hasRole(SystemRole::Support))->toBeTrue()
        // The account is reached through the reset flow, so no secret is
        // ever emailed or read aloud.
        ->and(Hash::check('password', (string) $created->password))->toBeFalse();
});

it('refuses to attach a customer-scoped role to staff', function (): void {
    $customerRole = Role::query()->where('slug', SystemRole::AccountOwner->value)->sole();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/staff', [
            'name' => 'Wrong Scope',
            'email' => 'wrong@example.test',
            'status' => AccountStatus::Active->value,
            'role_ids' => [$customerRole->id],
        ])
        ->assertRedirect();

    $created = StaffUser::query()->where('email', 'wrong@example.test')->sole();

    // The payload was accepted, but the scope filter dropped the role.
    expect($created->roles()->count())->toBe(0);
});

it('refuses to create a staff account with a duplicate address', function (): void {
    StaffUser::factory()->forOrganization($this->provider)->create(['email' => 'taken@example.test']);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/staff', [
            'name' => 'Duplicate',
            'email' => 'taken@example.test',
            'status' => AccountStatus::Active->value,
        ])
        ->assertSessionHasErrors('email');
});

it('updates a staff account and records the diff', function (): void {
    $member = StaffUser::factory()->forOrganization($this->provider)->create(['name' => 'Old Name']);
    $audit = $this->fakeAudit();

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/staff/'.$member->id, [
            'name' => 'New Name',
            'email' => $member->email,
            'status' => AccountStatus::Active->value,
        ])
        ->assertRedirect('/admin/staff');

    expect($member->fresh()->name)->toBe('New Name');

    $audit->assertRecorded(
        'identity.staff.updated',
        fn ($entry): bool => ($entry->changes['name']['to'] ?? null) === 'New Name',
    );
});

it('refuses to edit staff in another reseller', function (): void {
    $otherReseller = Organization::factory()->reseller($this->provider)->create();
    $theirs = StaffUser::factory()->forOrganization($otherReseller)->create();

    $reseller = Organization::factory()->reseller($this->provider)->create();
    $resellerAdmin = StaffUser::factory()->forOrganization($reseller)->create();
    $resellerAdmin->assignRole(SystemRole::Administrator);

    $this->actingAs($resellerAdmin->fresh(), 'staff')
        ->put('/admin/staff/'.$theirs->id, [
            'name' => 'Hijacked',
            'email' => $theirs->email,
            'status' => AccountStatus::Active->value,
        ])
        ->assertNotFound();

    expect($theirs->fresh()->name)->not->toBe('Hijacked');
});

it('refuses to delete your own account', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/staff/'.$this->admin->id)
        ->assertForbidden();

    expect(StaffUser::query()->find($this->admin->id))->not->toBeNull();
});

it('refuses to delete the last super administrator', function (): void {
    $superAdmin = StaffUser::factory()->forOrganization($this->provider)->create();
    $superAdmin->assignRole(SystemRole::SuperAdmin);

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/staff/'.$superAdmin->id)
        ->assertForbidden();

    expect(StaffUser::query()->find($superAdmin->id))->not->toBeNull();
});

it('deletes a super administrator while another one remains', function (): void {
    $first = StaffUser::factory()->forOrganization($this->provider)->create();
    $first->assignRole(SystemRole::SuperAdmin);

    $second = StaffUser::factory()->forOrganization($this->provider)->create();
    $second->assignRole(SystemRole::SuperAdmin);

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/staff/'.$first->id)
        ->assertRedirect('/admin/staff');

    expect(StaffUser::query()->find($first->id))->toBeNull()
        ->and(StaffUser::query()->find($second->id))->not->toBeNull();
});

it('turns off another staff member two-factor as a recovery path', function (): void {
    $member = StaffUser::factory()->forOrganization($this->provider)->withTwoFactor()->create();
    $audit = $this->fakeAudit();

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/staff/'.$member->id.'/two-factor')
        ->assertRedirect();

    expect($member->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $audit->assertRecorded('identity.two_factor.disabled_by_staff');
});

it('offers only staff-scoped roles on the form', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/staff/create')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $roles = $page->toArray()['props']['roles'];

            $slugs = array_column($roles, 'slug');

            expect($slugs)->not->toContain(SystemRole::AccountOwner->value)
                ->and($slugs)->toContain(SystemRole::Support->value);
        });
});

it('scopes the assignable role list to staff', function (): void {
    expect(Role::query()->forScope(RoleScope::Customer)->pluck('slug')->all())
        ->toBe([SystemRole::AccountOwner->value]);
});
