<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Identity\Models\StaffUser;

/**
 * Who may manage roles.
 *
 * Roles are installation-global in this phase, so there is no boundary check
 * to make. When per-reseller roles arrive, this policy grows one.
 */
final class RolePolicy
{
    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('access.roles.view');
    }

    public function view(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('access.roles.view');
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('access.roles.manage');
    }

    /**
     * A system role's permissions may be edited, but its slug and scope are
     * fixed: policies and upgrades refer to them by name.
     */
    public function update(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('access.roles.manage');
    }

    public function delete(StaffUser $actor, Role $role): bool
    {
        return ! $role->is_system && $actor->hasPermissionTo('access.roles.manage');
    }
}
