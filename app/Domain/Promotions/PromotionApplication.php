<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

/**
 * How long a discount lasts.
 */
enum PromotionApplication: string
{
    /** Discounts the first invoice only. */
    case FirstPayment = 'first_payment';

    /** Rides along with the service and discounts every renewal. */
    case Recurring = 'recurring';

    public function labelKey(): string
    {
        return 'ordering.promotion_applications.'.$this->value;
    }

    public function isRecurring(): bool
    {
        return $this === self::Recurring;
    }
}
