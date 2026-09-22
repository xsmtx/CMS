<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Crm\Models\Tag;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class TagPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('crm.customers.view');
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('crm.tags.manage');
    }

    public function update(StaffUser $actor, Tag $tag): bool
    {
        return $actor->hasPermissionTo('crm.tags.manage')
            && $this->withinBoundary($actor, $tag);
    }

    public function delete(StaffUser $actor, Tag $tag): bool
    {
        return $this->update($actor, $tag);
    }
}
