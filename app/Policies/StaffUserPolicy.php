<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\SystemRole;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Policies\Concerns\ChecksOrganizationBoundary;

/**
 * Who may manage staff accounts.
 */
final class StaffUserPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('identity.staff.view');
    }

    public function view(StaffUser $actor, StaffUser $staff): bool
    {
        return $actor->hasPermissionTo('identity.staff.view')
            && $this->withinBoundary($actor, $staff);
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('identity.staff.manage');
    }

    public function update(StaffUser $actor, StaffUser $staff): bool
    {
        return $actor->hasPermissionTo('identity.staff.manage')
            && $this->withinBoundary($actor, $staff);
    }

    /**
     * Nobody deletes themselves, and nobody deletes the last super admin.
     *
     * Both produce an installation that cannot be administered, and the
     * second is the one people discover at the worst possible moment.
     */
    public function delete(StaffUser $actor, StaffUser $staff): bool
    {
        if ($actor->id === $staff->id) {
            return false;
        }

        if ($this->isLastSuperAdmin($staff)) {
            return false;
        }

        return $actor->hasPermissionTo('identity.staff.manage')
            && $this->withinBoundary($actor, $staff);
    }

    /**
     * Suspending an account is reversible; the same two guards still apply,
     * because a suspended super admin cannot sign in either.
     */
    public function suspend(StaffUser $actor, StaffUser $staff): bool
    {
        return $this->delete($actor, $staff);
    }

    private function isLastSuperAdmin(StaffUser $staff): bool
    {
        if (! $staff->hasRole(SystemRole::SuperAdmin)) {
            return false;
        }

        $remaining = StaffUser::query()
            ->withoutGlobalScope('organization')
            ->whereKeyNot($staff->id)
            ->whereHas('roles', fn ($query) => $query->where('slug', SystemRole::SuperAdmin->value))
            ->count();

        return $remaining === 0;
    }
}
