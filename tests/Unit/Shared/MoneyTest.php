<?php

declare(strict_types=1);

use App\Domain\Shared\Currency;
use App\Domain\Shared\Exceptions\CurrencyMismatch;
use App\Domain\Shared\Exceptions\UnknownCurrency;
use App\Domain\Shared\Money;
use Brick\Math\Exception\RoundingNecessaryException;

it('stores an amount as minor units', function (): void {
    $money = Money::ofMinor(1999, 'USD');

    expect($money->minorUnits)->toBe(1999)
        ->and($money->currency->code)->toBe('USD')
        ->and($money->toDecimalString())->toBe('19.99');
});

it('parses a decimal string without passing through a float', function (): void {
    // 0.10 + 0.20 in doubles is 0.30000000000000004. Through minor units it
    // is 30, which is the whole reason this type exists.
    $sum = Money::ofDecimal('0.10', 'USD')->plus(Money::ofDecimal('0.20', 'USD'));

    expect($sum->minorUnits)->toBe(30)
        ->and($sum->toDecimalString())->toBe('0.30');
});

it('refuses a decimal with more precision than the currency has', function (): void {
    Money::ofDecimal('19.999', 'USD');
})->throws(RoundingNecessaryException::class);

it('adds, subtracts and multiplies', function (): void {
    $price = Money::ofMinor(1050, 'EUR');

    expect($price->plus(Money::ofMinor(450, 'EUR'))->minorUnits)->toBe(1500)
        ->and($price->minus(Money::ofMinor(50, 'EUR'))->minorUnits)->toBe(1000)
        ->and($price->multipliedBy(3)->minorUnits)->toBe(3150);
});

it('refuses arithmetic across currencies', function (): void {
    Money::ofMinor(100, 'USD')->plus(Money::ofMinor(100, 'EUR'));
})->throws(CurrencyMismatch::class);

it('refuses comparison across currencies', function (): void {
    Money::ofMinor(100, 'USD')->isGreaterThan(Money::ofMinor(100, 'EUR'));
})->throws(CurrencyMismatch::class);

it('treats equality across currencies as false rather than an error', function (): void {
    // Equality is a question that has an answer for two different
    // currencies, unlike ordering.
    expect(Money::ofMinor(100, 'USD')->equals(Money::ofMinor(100, 'EUR')))->toBeFalse();
});

it('applies a percentage with half-up rounding', function (): void {
    // 1999 * 20% = 399.8 minor units.
    expect(Money::ofMinor(1999, 'USD')->percentage('20')->minorUnits)->toBe(400)
        ->and(Money::ofMinor(1999, 'USD')->percentage('18')->minorUnits)->toBe(360);
});

it('allocates so that the parts sum exactly to the whole', function (): void {
    $parts = Money::ofMinor(100, 'USD')->allocateEvenly(3);

    expect(array_map(fn (Money $part): int => $part->minorUnits, $parts))->toBe([34, 33, 33])
        ->and(array_sum(array_map(fn (Money $part): int => $part->minorUnits, $parts)))->toBe(100);
});

it('allocates by weight', function (): void {
    $parts = Money::ofMinor(1000, 'USD')->allocate([3, 1]);

    expect(array_map(fn (Money $part): int => $part->minorUnits, $parts))->toBe([750, 250]);
});

it('allocates a negative amount without losing a unit', function (): void {
    // A credit note reconciles or it does not; truncation toward zero would
    // otherwise leave -99 of -100 distributed.
    $parts = Money::ofMinor(-100, 'USD')->allocateEvenly(3);

    expect(array_sum(array_map(fn (Money $part): int => $part->minorUnits, $parts)))->toBe(-100)
        ->and(array_map(fn (Money $part): int => $part->minorUnits, $parts))->toBe([-34, -33, -33]);
});

it('returns nothing for an empty or zero-weight allocation', function (): void {
    expect(Money::ofMinor(100, 'USD')->allocate([]))->toBe([])
        ->and(Money::ofMinor(100, 'USD')->allocate([0, 0]))->toBe([]);
});

it('handles a currency with no minor unit', function (): void {
    $yen = Money::ofMinor(1500, 'JPY');

    expect($yen->currency->exponent)->toBe(0)
        ->and($yen->toDecimalString())->toBe('1500');
});

it('handles a currency with three decimal places', function (): void {
    $dinar = Money::ofDecimal('1.234', 'KWD');

    expect($dinar->currency->exponent)->toBe(3)
        ->and($dinar->minorUnits)->toBe(1234)
        ->and($dinar->toDecimalString())->toBe('1.234');
});

it('reports sign', function (): void {
    expect(Money::zero('USD')->isZero())->toBeTrue()
        ->and(Money::ofMinor(1, 'USD')->isPositive())->toBeTrue()
        ->and(Money::ofMinor(-1, 'USD')->isNegative())->toBeTrue();
});

it('serialises without a float', function (): void {
    expect(Money::ofMinor(1999, 'USD')->jsonSerialize())
        ->toBe(['minor' => 1999, 'currency' => 'USD', 'decimal' => '19.99']);
});

it('has no way to become a float', function (): void {
    // Guarding the invariant, not the implementation: if someone adds a
    // float accessor this test is where the conversation happens.
    expect(method_exists(Money::class, 'toFloat'))->toBeFalse();
});

it('resolves a currency case-insensitively and rejects an unknown code', function (): void {
    expect(Currency::resolve('usd')->code)->toBe('USD');

    Currency::resolve('XXZ');
})->throws(UnknownCurrency::class);
