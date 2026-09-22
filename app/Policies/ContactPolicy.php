<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class ContactPolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('identity.contacts.view');
    }

    public function view(StaffUser $actor, Contact $contact): bool
    {
        return $actor->hasPermissionTo('identity.contacts.view')
            && $this->withinBoundary($actor, $contact);
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('identity.contacts.manage');
    }

    public function update(StaffUser $actor, Contact $contact): bool
    {
        return $actor->hasPermissionTo('identity.contacts.manage')
            && $this->withinBoundary($actor, $contact)
            && ! $contact->isAnonymized();
    }

    /**
     * The primary contact cannot be deleted while it is primary: a customer
     * with no primary contact has nobody to send an invoice to.
     */
    public function delete(StaffUser $actor, Contact $contact): bool
    {
        return ! $contact->is_primary
            && $actor->hasPermissionTo('identity.contacts.manage')
            && $this->withinBoundary($actor, $contact);
    }

    public function impersonate(StaffUser $actor, Contact $contact): bool
    {
        return $actor->hasPermissionTo('identity.contacts.impersonate')
            && $this->withinBoundary($actor, $contact)
            && $contact->canAuthenticate();
    }
}
