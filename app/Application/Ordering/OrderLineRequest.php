<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Domain\Catalog\BillingCycle;

/**
 * One product on the order.
 *
 * A separate object rather than an array because the override is the sort
 * of field that gets mistyped as a string once and is a silent price
 * change forever after.
 */
final readonly class OrderLineRequest
{
    public function __construct(
        public string $productId,
        public BillingCycle $cycle,
        public int $quantity = 1,
        public ?string $domain = null,
        /** Minor units. Null is not zero: null is "whatever the catalogue says". */
        public ?int $priceOverrideMinor = null,
    ) {}
}
