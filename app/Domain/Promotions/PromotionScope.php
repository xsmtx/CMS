<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

/**
 * What a promotion is allowed to discount.
 */
enum PromotionScope: string
{
    /** Everything in the cart. */
    case Order = 'order';

    /** Only the listed products. */
    case Products = 'products';

    /** Only setup fees, leaving the recurring price alone. */
    case SetupFees = 'setup_fees';

    public function labelKey(): string
    {
        return 'ordering.promotion_scopes.'.$this->value;
    }

    public function needsProducts(): bool
    {
        return $this === self::Products;
    }
}
