<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Identity\Models\StaffUser;

final class TicketPolicy
{
    public function viewAny(StaffUser $user): bool
    {
        return $user->can('support.tickets.view');
    }

    public function view(StaffUser $user): bool
    {
        return $user->can('support.tickets.view');
    }

    public function update(StaffUser $user): bool
    {
        return $user->can('support.tickets.manage');
    }

    public function delete(StaffUser $user): bool
    {
        return $user->can('support.tickets.delete');
    }
}
