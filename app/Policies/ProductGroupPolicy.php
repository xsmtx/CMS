<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class ProductGroupPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('catalog.groups.view');
    }

    public function view(StaffUser $actor, ProductGroup $group): bool
    {
        return $actor->hasPermissionTo('catalog.groups.view')
            && $this->withinBoundary($actor, $group);
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('catalog.groups.manage');
    }

    public function update(StaffUser $actor, ProductGroup $group): bool
    {
        return $actor->hasPermissionTo('catalog.groups.manage')
            && $this->withinBoundary($actor, $group);
    }

    public function delete(StaffUser $actor, ProductGroup $group): bool
    {
        return $this->update($actor, $group);
    }
}
