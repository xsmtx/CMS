<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Domain\Promotions\PromotionRefusal;
use App\Domain\Shared\Money;
use App\Domain\Tax\TaxResult;

/**
 * What a cart costs.
 *
 * Two numbers matter to a customer and they are different: what is due now,
 * and what renews. "149.90 now, 149.90 every month" and "299.80 now" are
 * different statements, and a totals object with one `total` cannot make
 * the first one.
 *
 * A refused promotion is carried here rather than thrown, because a cart
 * with a code that stopped applying is still a cart — the customer needs to
 * see the price and the reason, not an error page.
 */
final readonly class CartTotals
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function __construct(
        public string $currencyCode,
        public array $lines,
        public Money $subtotal,
        public Money $setup,
        public Money $discount,
        public TaxResult $tax,
        public Money $total,
        public Money $recurringTotal,
        public ?string $promotionCode = null,
        public ?string $promotionId = null,
        public ?PromotionRefusal $promotionRefusal = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function hasDiscount(): bool
    {
        return ! $this->discount->isZero();
    }

    public function hasSetupFees(): bool
    {
        return ! $this->setup->isZero();
    }

    /**
     * The amount tax was worked out on: everything due now, after the
     * discount. Tax is charged on what is paid.
     */
    public function taxableAmount(): Money
    {
        return $this->subtotal->plus($this->setup)->minus($this->discount);
    }

    /**
     * Lines that will still be billed after this order, for the "then
     * :amount" line on the cart.
     *
     * @return list<PricedLine>
     */
    public function recurringLines(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (PricedLine $line): bool => $line->isRecurring(),
        ));
    }
}
