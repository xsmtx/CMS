<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Identity\Models\StaffUser;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

it('refuses the role list without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin/roles')
        ->assertForbidden();
});

it('lists roles with their permission counts', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/roles')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Roles/Index')
            ->has('roles', 4));
});

it('creates a role with only the permissions it asked for', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/roles', [
            'name' => 'Billing Clerk',
            'slug' => 'billing-clerk',
            'scope' => RoleScope::Staff->value,
            'permission_slugs' => ['crm.customers.view', 'platform.audit.view'],
        ])
        ->assertRedirect('/admin/roles');

    $role = Role::query()->where('slug', 'billing-clerk')->sole();

    expect($role->permissions()->pluck('slug')->sort()->values()->all())
        ->toBe(['crm.customers.view', 'platform.audit.view']);
});

it('refuses a slug that is not url safe', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/roles', [
            'name' => 'Bad Slug',
            'slug' => 'Not A Slug!',
            'scope' => RoleScope::Staff->value,
        ])
        ->assertSessionHasErrors('slug');
});

it('refuses a duplicate slug', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/roles', [
            'name' => 'Clash',
            'slug' => SystemRole::Support->value,
            'scope' => RoleScope::Staff->value,
        ])
        ->assertSessionHasErrors('slug');
});

it('drops permissions that do not match the role scope', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/roles', [
            'name' => 'Mixed Scope',
            'slug' => 'mixed-scope',
            'scope' => RoleScope::Staff->value,
            // portal.* is customer scoped and must not attach to a staff role
            // however the form is crafted.
            'permission_slugs' => ['crm.customers.view', 'portal.dashboard.view'],
        ]);

    expect(Role::query()->where('slug', 'mixed-scope')->sole()->permissions()->pluck('slug')->all())
        ->toBe(['crm.customers.view']);
});

it('records what changed when a role is edited', function (): void {
    $role = Role::query()->where('slug', SystemRole::Support->value)->sole();
    $audit = $this->fakeAudit();

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/roles/'.$role->id, [
            'name' => $role->name,
            'slug' => $role->slug,
            'scope' => $role->scope->value,
            'permission_slugs' => ['crm.customers.view'],
        ])
        ->assertRedirect('/admin/roles');

    $audit->assertRecorded('access.role.updated');

    expect($role->fresh()->permissions()->pluck('slug')->all())->toBe(['crm.customers.view']);
});

it('keeps a system role slug and scope fixed', function (): void {
    $role = Role::query()->where('slug', SystemRole::Support->value)->sole();

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/roles/'.$role->id, [
            'name' => 'Renamed Support',
            'slug' => 'renamed-support',
            'scope' => RoleScope::Customer->value,
            'permission_slugs' => [],
        ]);

    $fresh = $role->fresh();

    expect($fresh->name)->toBe('Renamed Support')
        ->and($fresh->slug)->toBe(SystemRole::Support->value)
        ->and($fresh->scope)->toBe(RoleScope::Staff);
});

it('refuses to delete a system role', function (): void {
    $role = Role::query()->where('slug', SystemRole::Support->value)->sole();

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/roles/'.$role->id)
        ->assertForbidden();

    expect(Role::query()->find($role->id))->not->toBeNull();
});

it('deletes a role it created', function (): void {
    $role = Role::factory()->create();

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/roles/'.$role->id)
        ->assertRedirect('/admin/roles');

    expect(Role::query()->find($role->id))->toBeNull();
});

it('applies a permission change to everyone holding the role immediately', function (): void {
    $role = Role::factory()->create();

    $member = StaffUser::factory()->create();
    $member->assignRole($role);

    expect($member->fresh()->hasPermissionTo('crm.customers.view'))->toBeFalse();

    $this->actingAs($this->admin, 'staff')->put('/admin/roles/'.$role->id, [
        'name' => $role->name,
        'slug' => $role->slug,
        'scope' => $role->scope->value,
        'permission_slugs' => ['crm.customers.view'],
    ]);

    // The cache generation was bumped, so the assignee re-resolves.
    expect($member->fresh()->hasPermissionTo('crm.customers.view'))->toBeTrue();
});

it('builds the permission matrix from the registry', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/roles/create')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $groups = $page->toArray()['props']['permissionGroups'];

            $slugs = collect($groups)->flatMap(fn (array $group): array => $group['permissions'])
                ->pluck('slug')
                ->all();

            expect($slugs)->toEqualCanonicalizing(app(PermissionRegistry::class)->slugs());
        });
});
