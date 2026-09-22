<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Whether a catalog item can be ordered, and whether it is listed.
 *
 * Three states rather than a boolean, because "orderable by someone who has
 * the link" is a real thing every hosting platform needs: a legacy plan kept
 * for renewals, or a deal agreed over the phone.
 */
enum CatalogStatus: string
{
    case Active = 'active';
    case Hidden = 'hidden';
    case Retired = 'retired';

    public function labelKey(): string
    {
        return 'catalog.statuses.'.$this->value;
    }

    /**
     * Whether it appears in storefront listings.
     */
    public function isListed(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether a new order may be placed. Hidden items are orderable by
     * direct link; retired ones are not orderable at all, though existing
     * services on them keep renewing.
     */
    public function isOrderable(): bool
    {
        return $this === self::Active || $this === self::Hidden;
    }
}
