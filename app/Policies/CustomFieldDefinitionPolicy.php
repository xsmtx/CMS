<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class CustomFieldDefinitionPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('crm.customers.view');
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('crm.custom_fields.manage');
    }

    public function update(StaffUser $actor, CustomFieldDefinition $definition): bool
    {
        return $actor->hasPermissionTo('crm.custom_fields.manage')
            && $this->withinBoundary($actor, $definition);
    }

    /**
     * Deleting a definition destroys every value stored against it, which is
     * customer data. The permission is the same, but the UI asks twice.
     */
    public function delete(StaffUser $actor, CustomFieldDefinition $definition): bool
    {
        return $this->update($actor, $definition);
    }
}
