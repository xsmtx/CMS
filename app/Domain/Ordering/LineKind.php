<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

/**
 * What a cart or order line is for.
 *
 * A domain line carries a name and a term where a product line carries a
 * billing cycle and options. Keeping them in one table rather than two means
 * a cart is ordered, priced and totalled once; keeping them apart by kind
 * means neither pretends to be the other.
 */
enum LineKind: string
{
    case Product = 'product';

    /** Sold alongside a product line, billed on its own line. */
    case Addon = 'addon';

    case Domain = 'domain';

    public function labelKey(): string
    {
        return 'ordering.line_kinds.'.$this->value;
    }

    public function isDomain(): bool
    {
        return $this === self::Domain;
    }

    /**
     * Whether the line hangs off another one. An addon without its product
     * is not a thing anyone ordered.
     */
    public function needsParent(): bool
    {
        return $this === self::Addon;
    }
}
