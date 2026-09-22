<?php

declare(strict_types=1);

namespace App\Domain\Organizations;

/**
 * Every record in the platform is owned by exactly one organization.
 *
 * There is a single `provider` organization — the installation owner. A
 * `reseller` sells the provider's products under its own brand and owns its
 * own customers. A `customer` organization is an end customer's account,
 * which may itself contain several contacts.
 */
enum OrganizationType: string
{
    case Provider = 'provider';
    case Reseller = 'reseller';
    case Customer = 'customer';

    public function labelKey(): string
    {
        return 'organizations.types.'.$this->value;
    }

    /**
     * Whether an organization of this type may own child organizations.
     */
    public function canOwnChildren(): bool
    {
        return match ($this) {
            self::Provider, self::Reseller => true,
            self::Customer => false,
        };
    }

    /**
     * The types an organization of this type is allowed to create beneath it.
     *
     * @return list<self>
     */
    public function permittedChildTypes(): array
    {
        return match ($this) {
            self::Provider => [self::Reseller, self::Customer],
            self::Reseller => [self::Customer],
            self::Customer => [],
        };
    }
}
