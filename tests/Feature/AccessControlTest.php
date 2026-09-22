<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\CorePermissions;
use App\Domain\Access\PermissionDefinition;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Access\Models\Permission;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Identity\Models\StaffUser;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Gate;

function syncCorePermissions(): void
{
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
}

it('mirrors every declared permission into the database', function (): void {
    syncCorePermissions();

    expect(Permission::query()->count())->toBe(count(CorePermissions::all()));
});

it('is idempotent: a second run changes nothing', function (): void {
    syncCorePermissions();

    $second = app(SyncPermissions::class)->handle(app(PermissionRegistry::class));

    expect($second->changedAnything())->toBeFalse();
});

it('orphans a permission that disappears from code instead of deleting it', function (): void {
    syncCorePermissions();

    Permission::query()->create([
        'slug' => 'legacy.thing.manage',
        'group' => 'legacy',
        'scope' => RoleScope::Staff->value,
        'is_high_risk' => false,
    ]);

    $result = app(SyncPermissions::class)->handle(app(PermissionRegistry::class));

    expect($result->orphaned)->toBe(['legacy.thing.manage'])
        ->and(Permission::query()->where('slug', 'legacy.thing.manage')->sole()->isOrphaned())->toBeTrue();
});

it('restores a permission when the module that declared it comes back', function (): void {
    syncCorePermissions();

    $permission = Permission::query()->where('slug', 'settings.manage')->sole();
    $permission->forceFill(['orphaned_at' => now()])->save();

    $result = app(SyncPermissions::class)->handle(app(PermissionRegistry::class));

    expect($result->restored)->toContain('settings.manage')
        ->and(Permission::query()->where('slug', 'settings.manage')->sole()->isOrphaned())->toBeFalse();
});

it('grants a capability through a role rather than a flag on the user', function (): void {
    syncCorePermissions();
    $this->seed(SystemRoleSeeder::class);

    $user = StaffUser::factory()->create();

    expect($user->hasPermissionTo('settings.manage'))->toBeFalse();

    $user->assignRole(SystemRole::Administrator);

    expect($user->fresh()->hasPermissionTo('settings.manage'))->toBeTrue();
});

it('revokes the capability when the role is removed', function (): void {
    syncCorePermissions();
    $this->seed(SystemRoleSeeder::class);

    $user = StaffUser::factory()->create();
    $user->assignRole(SystemRole::Administrator);
    $user->revokeRole(SystemRole::Administrator);

    expect($user->fresh()->hasPermissionTo('settings.manage'))->toBeFalse();
});

it('refuses to assign a customer-scoped role to a staff subject', function (): void {
    syncCorePermissions();
    $this->seed(SystemRoleSeeder::class);

    StaffUser::factory()->create()->assignRole(SystemRole::AccountOwner);
})->throws(InvalidArgumentException::class);

it('ignores permissions that have been orphaned', function (): void {
    syncCorePermissions();
    $this->seed(SystemRoleSeeder::class);

    $user = StaffUser::factory()->create();
    $user->assignRole(SystemRole::Administrator);

    Permission::query()->where('slug', 'settings.manage')->sole()
        ->forceFill(['orphaned_at' => now()])->save();

    expect($user->fresh()->hasPermissionTo('settings.manage'))->toBeFalse();
});

it('lets a super admin through without explicit grants', function (): void {
    syncCorePermissions();
    $this->seed(SystemRoleSeeder::class);

    $user = StaffUser::factory()->create();
    $user->assignRole(SystemRole::SuperAdmin);

    $user = $user->fresh();

    expect($user->hasPermissionTo('settings.manage'))->toBeTrue()
        ->and($user->can('settings.manage'))->toBeTrue()
        ->and(Role::query()->where('slug', SystemRole::SuperAdmin->value)->sole()->permissions)->toBeEmpty();
});

it('audits a super admin bypassing a high-risk capability', function (): void {
    syncCorePermissions();
    $this->seed(SystemRoleSeeder::class);
    $audit = $this->fakeAudit();

    $user = StaffUser::factory()->create();
    $user->assignRole(SystemRole::SuperAdmin);

    $user->fresh()->can('settings.manage');

    $audit->assertRecorded(
        'access.superadmin.bypass',
        fn ($entry): bool => ($entry->metadata['ability'] ?? null) === 'settings.manage',
    );
});

it('denies a capability the subject has no role for', function (): void {
    syncCorePermissions();
    $this->seed(SystemRoleSeeder::class);

    $user = StaffUser::factory()->create();
    $user->assignRole(SystemRole::Support);

    expect($user->fresh()->can('settings.manage'))->toBeFalse()
        ->and($user->fresh()->can('platform.audit.view'))->toBeTrue();
});

it('refuses to delete a system role', function (): void {
    $this->seed(SystemRoleSeeder::class);

    Role::query()->where('slug', SystemRole::SuperAdmin->value)->sole()->delete();
})->throws(RuntimeException::class, 'System role');

it('defines a gate for every declared permission', function (): void {
    foreach (app(PermissionRegistry::class)->slugs() as $slug) {
        expect(Gate::has($slug))->toBeTrue();
    }
});

it('declares no permission slug twice across core and modules', function (): void {
    $registry = new PermissionRegistry(CorePermissions::all());

    $registry->register(
        new PermissionDefinition('module.only.view', 'module', RoleScope::Staff, module: 'demo'),
    );

    expect(count($registry->slugs()))->toBe(count(array_unique($registry->slugs())));
});

it('lists every staff permission for a super admin', function (): void {
    // The role bypasses the check rather than holding grants, so reading its
    // assignments would say it holds nothing — and the admin navigation,
    // which asks this question to decide what to show, would come up empty.
    syncCorePermissions();
    $this->seed(SystemRoleSeeder::class);

    $owner = StaffUser::factory()->create();
    $owner->assignRole(SystemRole::SuperAdmin);

    $staffSlugs = array_values(array_map(
        static fn (PermissionDefinition $definition): string => $definition->slug,
        app(PermissionRegistry::class)->forScope(RoleScope::Staff),
    ));

    expect($owner->fresh()?->effectivePermissions())->toEqualCanonicalizing($staffSlugs)
        ->and($owner->fresh()?->effectivePermissions())->toContain('catalog.products.view')
        ->and($owner->fresh()?->effectivePermissions())->not->toContain('portal.dashboard.view');
});
