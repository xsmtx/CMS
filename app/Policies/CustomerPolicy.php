<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class CustomerPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('crm.customers.view');
    }

    public function view(StaffUser $actor, Customer $customer): bool
    {
        return $actor->hasPermissionTo('crm.customers.view')
            && $this->withinBoundary($actor, $customer);
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('crm.customers.manage');
    }

    public function update(StaffUser $actor, Customer $customer): bool
    {
        return $actor->hasPermissionTo('crm.customers.manage')
            && $this->withinBoundary($actor, $customer)
            // An anonymised customer is a tombstone. Editing one would
            // reintroduce the personal data the erasure removed.
            && ! $customer->isAnonymized();
    }

    /**
     * Producing a complete copy of a person's data is its own capability,
     * separate from being able to read the record on screen.
     */
    public function export(StaffUser $actor, Customer $customer): bool
    {
        return $actor->hasPermissionTo('crm.customers.export')
            && $this->withinBoundary($actor, $customer);
    }

    public function anonymize(StaffUser $actor, Customer $customer): bool
    {
        return $actor->hasPermissionTo('crm.customers.anonymize')
            && $this->withinBoundary($actor, $customer)
            && ! $customer->isAnonymized();
    }
}
