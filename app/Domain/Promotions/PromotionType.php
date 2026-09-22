<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

enum PromotionType: string
{
    /** A fixed amount off, in one currency. */
    case Fixed = 'fixed';

    /** A percentage off the eligible subtotal. */
    case Percentage = 'percentage';

    public function labelKey(): string
    {
        return 'ordering.promotion_types.'.$this->value;
    }

    /**
     * A fixed discount is an amount of money, so it belongs to a currency
     * and applies only to carts in it. Converting it would be the same
     * mistake as converting a price.
     */
    public function requiresCurrency(): bool
    {
        return $this === self::Fixed;
    }
}
