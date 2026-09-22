<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Permissions owned by the platform core.
 *
 * Phase 0 declares only the capabilities the foundation itself exposes.
 * Each later phase adds the permissions for the context it introduces; a
 * permission is never removed without marking the database row orphaned, so
 * that historical role assignments remain explainable.
 */
final class CorePermissions
{
    /**
     * @return list<PermissionDefinition>
     */
    public static function all(): array
    {
        return [
            // Operations
            new PermissionDefinition('platform.health.view', 'platform', RoleScope::Staff),
            new PermissionDefinition('platform.queue.view', 'platform', RoleScope::Staff),
            new PermissionDefinition('platform.audit.view', 'platform', RoleScope::Staff),

            // Access control
            new PermissionDefinition('access.roles.view', 'access', RoleScope::Staff),
            new PermissionDefinition('access.roles.manage', 'access', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('access.permissions.view', 'access', RoleScope::Staff),

            // Settings and white-label configuration
            new PermissionDefinition('settings.view', 'settings', RoleScope::Staff),
            new PermissionDefinition('settings.manage', 'settings', RoleScope::Staff, highRisk: true),

            // Organizations and reseller boundary
            new PermissionDefinition('organizations.view', 'organizations', RoleScope::Staff),
            new PermissionDefinition('organizations.manage', 'organizations', RoleScope::Staff, highRisk: true),

            // Client portal
            new PermissionDefinition('portal.dashboard.view', 'portal', RoleScope::Customer),
        ];
    }
}
