<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class OrganizationPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('organizations.view');
    }

    public function view(StaffUser $actor, Organization $organization): bool
    {
        return $actor->hasPermissionTo('organizations.view')
            && $this->withinBoundary($actor, $organization);
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('organizations.manage');
    }

    public function update(StaffUser $actor, Organization $organization): bool
    {
        return $actor->hasPermissionTo('organizations.manage')
            && $this->withinBoundary($actor, $organization);
    }

    /**
     * The provider organization is the root of the hierarchy. Deleting it
     * would orphan every record in the installation.
     */
    public function delete(StaffUser $actor, Organization $organization): bool
    {
        return ! $organization->isProvider()
            && $actor->hasPermissionTo('organizations.manage')
            && $this->withinBoundary($actor, $organization);
    }
}
