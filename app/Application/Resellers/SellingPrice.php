<?php

declare(strict_types=1);

namespace App\Application\Resellers;

use App\Domain\Shared\Money;

/**
 * What a buyer pays, and whether a margin got it there.
 *
 * The margin is carried so a screen can say "the provider's price plus 15%"
 * rather than only showing a number. An operator checking a reseller's
 * pricing wants to see the working, and a number with no explanation is a
 * number somebody re-derives by hand.
 */
final readonly class SellingPrice
{
    public function __construct(
        public Money $recurring,
        public Money $setup,
        /** A decimal string, or null when the price is not a markup. */
        public ?string $marginPercent = null,
    ) {}

    public function isMarkedUp(): bool
    {
        return $this->marginPercent !== null;
    }
}
