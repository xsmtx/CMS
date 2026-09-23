<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Access\Models\Permission;
use App\Infrastructure\Access\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Creates the platform-owned roles and gives each one its default permission
 * set. Idempotent: it runs on every deployment.
 *
 * `super-admin` is intentionally left without explicit grants — it bypasses
 * permission checks entirely, and duplicating the whole catalogue onto it
 * would only create a second, drifting source of truth.
 */
final class SystemRoleSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(PermissionRegistry::class);

        // Roles reference permission rows, so the registry must already be
        // mirrored. Syncing here rather than relying on command ordering
        // removes a trap that only shows up as silently empty roles.
        app(SyncPermissions::class)->handle($registry);

        foreach (SystemRole::cases() as $systemRole) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $systemRole->value],
                [
                    'scope' => $systemRole->scope()->value,
                    'name' => (string) __($systemRole->labelKey()),
                    'is_system' => true,
                ],
            );

            $slugs = $this->defaultPermissionsFor($systemRole, $registry);

            if ($slugs === []) {
                continue;
            }

            $ids = Permission::query()
                ->active()
                ->whereIn('slug', $slugs)
                ->pluck('id')
                ->all();

            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    /**
     * @return list<string>
     */
    private function defaultPermissionsFor(SystemRole $role, PermissionRegistry $registry): array
    {
        return match ($role) {
            SystemRole::SuperAdmin => [],
            SystemRole::Administrator => array_keys($registry->forScope(RoleScope::Staff)),
            // A support agent answers tickets and writes the article that
            // stops the next one. Not the delete permission, and not the
            // template editor: one changes what every customer is told.
            SystemRole::Support => [
                'platform.health.view',
                'platform.audit.view',
                'access.roles.view',
                'settings.view',
                'support.tickets.view',
                'support.tickets.manage',
                'content.announcements.manage',
                'content.kb.manage',
                'notifications.view',
                'operations.view',
                'automation.view',
            ],
            SystemRole::AccountOwner => array_keys($registry->forScope(RoleScope::Customer)),
            // A technical contact or an employee: enough to see what was
            // ordered and to look after their own sign-in, and nothing
            // financial. The WHMCS idea of contact permissions, expressed
            // as a role rather than as eight checkboxes on a contact.
            SystemRole::PortalMember => [
                'portal.dashboard.view',
                'portal.profile.view',
                'portal.security.manage',
                'portal.orders.view',
            ],
        };
    }
}
