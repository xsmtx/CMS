<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Shared\Exceptions\CurrencyMismatch;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use JsonSerializable;
use Stringable;

/**
 * An amount of money.
 *
 * Always an integer count of minor units paired with an ISO 4217 code. IEEE
 * 754 doubles cannot represent 0.10 exactly, and a financial system that is
 * off by a cent is wrong, so there is deliberately no `toFloat()` and no
 * constructor that takes one.
 *
 * `brick/money` does the arithmetic. It is wrapped rather than used directly
 * so that the rest of the platform depends on this type: swapping the
 * library, or adding a rule like "never round a tax line up", is one edit.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $minorUnits,
        public Currency $currency,
    ) {}

    public function __toString(): string
    {
        return $this->toDecimalString().' '.$this->currency->code;
    }

    /**
     * The canonical constructor: minor units, as stored.
     */
    public static function ofMinor(int $minorUnits, Currency|string $currency): self
    {
        return new self($minorUnits, Currency::resolve($currency));
    }

    /**
     * Build from a decimal string such as "19.99".
     *
     * A string, never a float: the point of this class is that the decimal
     * never passes through binary floating point on its way in.
     */
    public static function ofDecimal(string $amount, Currency|string $currency): self
    {
        $resolved = Currency::resolve($currency);

        $money = BrickMoney::of($amount, $resolved->code, roundingMode: RoundingMode::Unnecessary);

        return new self($money->getMinorAmount()->toInt(), $resolved);
    }

    public static function zero(Currency|string $currency): self
    {
        return new self(0, Currency::resolve($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiply by a whole number, for a quantity.
     */
    public function multipliedBy(int $factor): self
    {
        return new self($this->minorUnits * $factor, $this->currency);
    }

    /**
     * Apply a percentage, rounding half up.
     *
     * Used for discounts and tax. The rounding mode is named here rather
     * than left to a default, because a default is what produces totals
     * nobody can reconcile.
     */
    public function percentage(string $percent): self
    {
        $result = $this->toBrick()
            ->multipliedBy($percent, RoundingMode::HalfUp)
            ->dividedBy(100, RoundingMode::HalfUp);

        return new self($result->getMinorAmount()->toInt(), $this->currency);
    }

    /**
     * Split across n parts so that the parts sum exactly to the whole.
     *
     * Largest-remainder distribution: the leftover minor units go to the
     * earliest parts, one each. Dividing 100 by 3 gives 34, 33, 33 rather
     * than three 33s and a missing cent, which is the difference between an
     * invoice that reconciles and one that does not.
     *
     * @return list<self>
     */
    public function allocateEvenly(int $parts): array
    {
        return $this->allocate(array_fill(0, max($parts, 1), 1));
    }

    /**
     * Split in proportion to the given weights, summing exactly to the whole.
     *
     * @param  list<int>  $weights
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        $total = array_sum($weights);

        if ($weights === [] || $total <= 0) {
            return [];
        }

        $allocated = [];
        $remainder = $this->minorUnits;

        foreach ($weights as $weight) {
            // intdiv truncates toward zero, so each share is never further
            // from zero than its exact value and the remainder always
            // carries the sign of the whole.
            $share = intdiv($this->minorUnits * $weight, $total);
            $allocated[] = $share;
            $remainder -= $share;
        }

        // A negative whole leaves a negative remainder; a credit note has to
        // reconcile exactly as much as an invoice does.
        $step = $remainder < 0 ? -1 : 1;

        for ($index = 0; $remainder !== 0; $index++, $remainder -= $step) {
            $allocated[$index % count($allocated)] += $step;
        }

        return array_values(array_map(fn (int $minor): self => new self($minor, $this->currency), $allocated));
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency->code === $other->currency->code;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    /**
     * The decimal representation, as a string. For display and for storage
     * in documents; never parsed back into a float.
     */
    public function toDecimalString(): string
    {
        return (string) $this->toBrick()->getAmount();
    }

    /**
     * Formatted for a locale, with the currency symbol.
     */
    public function format(string $locale = 'en'): string
    {
        return $this->toBrick()->formatToLocale($locale);
    }

    /**
     * @return array{minor: int, currency: string, decimal: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'minor' => $this->minorUnits,
            'currency' => $this->currency->code,
            'decimal' => $this->toDecimalString(),
        ];
    }

    private function toBrick(): BrickMoney
    {
        return BrickMoney::ofMinor($this->minorUnits, $this->currency->code);
    }

    /**
     * Arithmetic across currencies is always a bug: there is no rate in
     * scope, and silently picking one would produce a number nobody can
     * explain.
     */
    private function assertSameCurrency(self $other): void
    {
        if ($this->currency->code !== $other->currency->code) {
            throw CurrencyMismatch::between($this->currency->code, $other->currency->code);
        }
    }
}
