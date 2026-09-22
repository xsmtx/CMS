<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Roles the platform owns. They are seeded, cannot be deleted and cannot be
 * renamed by an operator, because policies and upgrades refer to them.
 */
enum SystemRole: string
{
    /**
     * The only role that bypasses individual permission checks. Every bypass
     * is audited so that the shortcut stays visible.
     */
    case SuperAdmin = 'super-admin';

    case Administrator = 'administrator';

    case Support = 'support';

    case AccountOwner = 'account-owner';

    /**
     * A contact with portal access who is not the account owner. They can
     * see the account; they cannot change who else reaches it.
     */
    case PortalMember = 'portal-member';

    public function scope(): RoleScope
    {
        return match ($this) {
            self::SuperAdmin, self::Administrator, self::Support => RoleScope::Staff,
            self::AccountOwner, self::PortalMember => RoleScope::Customer,
        };
    }

    public function labelKey(): string
    {
        return 'access.roles.'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
