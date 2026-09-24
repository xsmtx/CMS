<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * Who a rule applies to.
 *
 * Businesses and consumers are taxed differently in enough places to be worth a
 * column, and the distinction here is only "did they give us a company or a
 * tax id" — core does not decide what a business *is* in any jurisdiction.
 */
enum TaxCustomerKind: string
{
    case All = 'all';
    case Individual = 'individual';
    case Business = 'business';

    public function covers(bool $isBusiness): bool
    {
        return match ($this) {
            self::All => true,
            self::Business => $isBusiness,
            self::Individual => ! $isBusiness,
        };
    }

    public function labelKey(): string
    {
        return 'tax.customer_kinds.'.$this->value;
    }
}
