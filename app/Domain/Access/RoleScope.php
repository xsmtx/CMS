<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Roles and permissions are partitioned by scope so that a customer-facing
 * role can never be granted a staff capability, however the data is edited.
 */
enum RoleScope: string
{
    case Staff = 'staff';
    case Customer = 'customer';

    public function labelKey(): string
    {
        return 'access.scopes.'.$this->value;
    }
}
