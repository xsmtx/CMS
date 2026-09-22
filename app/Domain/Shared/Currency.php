<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Shared\Exceptions\UnknownCurrency;
use Brick\Money\Currency as BrickCurrency;
use Brick\Money\Exception\UnknownCurrencyException;
use Stringable;

/**
 * An ISO 4217 currency.
 *
 * Carries its own exponent rather than assuming two decimals. JPY has none
 * and KWD has three, and code that assumes two is a bug waiting for the
 * first Japanese customer.
 */
final readonly class Currency implements Stringable
{
    private function __construct(
        public string $code,
        public string $name,
        public int $exponent,
    ) {}

    public function __toString(): string
    {
        return $this->code;
    }

    public static function of(string $code): self
    {
        try {
            $currency = BrickCurrency::of(strtoupper($code));
        } catch (UnknownCurrencyException) {
            throw UnknownCurrency::code($code);
        }

        return new self(
            $currency->getCurrencyCode(),
            $currency->getName(),
            $currency->getDefaultFractionDigits(),
        );
    }

    public static function resolve(self|string $currency): self
    {
        return $currency instanceof self ? $currency : self::of($currency);
    }

    /**
     * How many minor units make one major unit. 100 for EUR, 1 for JPY.
     */
    public function minorUnitsPerMajor(): int
    {
        return 10 ** $this->exponent;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
