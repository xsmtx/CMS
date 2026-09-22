<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

/**
 * Why a code did not apply.
 *
 * Each case is a separate message, because "that code is not valid" for an
 * expired code, a used-up code and a code that needs a bigger order sends
 * the customer away when two of the three are fixable in the cart.
 */
enum PromotionRefusal: string
{
    case NotFound = 'not_found';
    case Inactive = 'inactive';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case UsageLimitReached = 'usage_limit_reached';
    case CustomerLimitReached = 'customer_limit_reached';
    case MinimumNotMet = 'minimum_not_met';
    case NewCustomersOnly = 'new_customers_only';
    case WrongCurrency = 'wrong_currency';
    case NoEligibleItems = 'no_eligible_items';
    case CycleNotEligible = 'cycle_not_eligible';
    case NotStackable = 'not_stackable';

    public function messageKey(): string
    {
        return 'ordering.promotion_refusals.'.$this->value;
    }

    /**
     * Whether the customer can do something about it without leaving the
     * cart. Drives whether the message is advice or an apology.
     */
    public function isFixableInCart(): bool
    {
        return match ($this) {
            self::MinimumNotMet, self::NoEligibleItems, self::CycleNotEligible, self::WrongCurrency => true,
            default => false,
        };
    }
}
