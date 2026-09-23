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

    /**
     * Opening one on a customer's behalf is managing the queue.
     *
     * This method was missing, so `authorize('create', Ticket::class)` fell
     * through to a denial and **Open New Ticket answered 403 to
     * everybody, including the owner**. Nothing caught it because no test
     * had ever rendered the screen.
     */
    public function create(StaffUser $user): bool
    {
        return $user->can('support.tickets.manage');
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
