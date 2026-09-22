<?php

declare(strict_types=1);

namespace App\Application\Promotions;

use App\Application\Ordering\PricedLine;
use App\Domain\Promotions\PromotionRefusal;
use App\Domain\Shared\Money;

/**
 * What a promotion did to a cart.
 *
 * The lines come back with their share of the discount already allocated,
 * because an invoice in Phase 4 is written per line and the shares have to
 * sum exactly to the order's discount.
 */
final readonly class DiscountResult
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function __construct(
        public Money $discount,
        public array $lines,
        public ?string $promotionId = null,
        public ?PromotionRefusal $refusal = null,
    ) {}

    /**
     * @param  list<PricedLine>  $lines
     */
    public static function none(array $lines, Money $zero, ?PromotionRefusal $refusal = null): self
    {
        return new self($zero, $lines, null, $refusal);
    }
}
