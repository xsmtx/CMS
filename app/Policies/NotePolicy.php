<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Crm\Models\Note;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Policies\Concerns\ChecksOrganizationBoundary;

final class NotePolicy
{
    use ChecksOrganizationBoundary;

    public function viewAny(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('crm.notes.view');
    }

    public function create(StaffUser $actor): bool
    {
        return $actor->hasPermissionTo('crm.notes.manage');
    }

    public function update(StaffUser $actor, Note $note): bool
    {
        return $actor->hasPermissionTo('crm.notes.manage')
            && $this->withinBoundary($actor, $note);
    }

    public function delete(StaffUser $actor, Note $note): bool
    {
        return $this->update($actor, $note);
    }
}
