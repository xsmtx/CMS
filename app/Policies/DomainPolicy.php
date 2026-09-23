<?php

declare(strict_types=1);

namespace App\Policies;

use App\Infrastructure\Identity\Models\StaffUser;

/**
 * Who may do what to a name.
 *
 * Registering has its own permission and its own answer: it spends money at
 * a registrar and cannot be taken back, so an operator who may change
 * nameservers is not thereby allowed to buy a domain.
 */
final class DomainPolicy
{
    public function viewAny(StaffUser $user): bool
    {
        return $user->can('domains.view');
    }

    public function view(StaffUser $user): bool
    {
        return $user->can('domains.view');
    }

    public function update(StaffUser $user): bool
    {
        return $user->can('domains.manage');
    }

    public function register(StaffUser $user): bool
    {
        return $user->can('domains.register');
    }
}
