<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Casts;

use App\Domain\Shared\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores a Money as an integer column plus a currency column.
 *
 * Two columns rather than one string, so the amount stays an integer the
 * database can sum, compare and index. The cast takes the names of both, so
 * a model with several amounts in one currency writes the currency once.
 *
 * Usage: `'price' => MoneyCast::class.':price_minor,currency_code'`
 *
 * @implements CastsAttributes<Money|null, Money|null>
 */
final readonly class MoneyCast implements CastsAttributes
{
    public function __construct(
        private string $amountColumn = 'amount_minor',
        private string $currencyColumn = 'currency_code',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $minor = $attributes[$this->amountColumn] ?? null;
        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($minor === null || ! is_string($currency) || $currency === '') {
            return null;
        }

        return Money::ofMinor((int) $minor, $currency);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$this->amountColumn => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] attribute must be set to a %s, %s given.',
                $key,
                Money::class,
                get_debug_type($value),
            ));
        }

        // The currency is written alongside the amount, so the pair can
        // never be half-updated into a row that means something else.
        return [
            $this->amountColumn => $value->minorUnits,
            $this->currencyColumn => $value->currency->code,
        ];
    }
}
