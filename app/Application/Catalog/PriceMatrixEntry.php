<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Exceptions\CurrencyMismatch;
use App\Domain\Shared\Money;

/**
 * One cell of a price matrix: what an item costs on one cycle, in one
 * currency.
 *
 * The currency comes from the money rather than sitting beside it, so a cell
 * whose setup fee is in a different currency from its recurring fee cannot
 * be constructed at all.
 */
final readonly class PriceMatrixEntry
{
    public function __construct(
        public BillingCycle $cycle,
        public Money $recurring,
        public Money $setup,
    ) {
        if ($recurring->currency->code !== $setup->currency->code) {
            throw CurrencyMismatch::between($recurring->currency->code, $setup->currency->code);
        }
    }

    public static function of(BillingCycle $cycle, int $recurringMinor, int $setupMinor, string $currencyCode): self
    {
        return new self(
            $cycle,
            Money::ofMinor($recurringMinor, $currencyCode),
            Money::ofMinor($setupMinor, $currencyCode),
        );
    }

    public function currencyCode(): string
    {
        return $this->recurring->currency->code;
    }

    /**
     * The cell's coordinates, used to detect a matrix that names the same
     * cell twice.
     */
    public function cellKey(): string
    {
        return $this->cycle->value.':'.$this->currencyCode();
    }
}
