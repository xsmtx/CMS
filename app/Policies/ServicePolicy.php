<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Identity\Models\StaffUser;

/**
 * Who may do what to a running service.
 *
 * Terminating has its own permission and its own answer: it destroys an
 * account at a provider and nothing brings it back, so an operator who may
 * suspend is not thereby allowed to delete.
 */
final class ServicePolicy
{
    public function viewAny(StaffUser $user): bool
    {
        return $user->can('services.view');
    }

    public function view(StaffUser $user): bool
    {
        return $user->can('services.view');
    }

    public function update(StaffUser $user): bool
    {
        return $user->can('services.manage');
    }

    public function provision(StaffUser $user): bool
    {
        return $user->can('services.provision');
    }

    public function suspend(StaffUser $user): bool
    {
        return $user->can('services.suspend');
    }

    public function terminate(StaffUser $user): bool
    {
        return $user->can('services.terminate');
    }
}
