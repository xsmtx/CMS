<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Domain\Shared\Money;

/**
 * One configurable choice, priced.
 *
 * Carries the wording as well as the amounts, because the order line copies
 * both and the cart displays both.
 */
final readonly class PricedOption
{
    public function __construct(
        public string $groupId,
        public string $groupName,
        public string $groupKey,
        public ?string $optionId,
        public string $label,
        public string $value,
        public int $quantity,
        public Money $recurring,
        public Money $setup,
    ) {}
}
