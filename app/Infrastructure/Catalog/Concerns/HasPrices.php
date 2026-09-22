<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Concerns;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared by everything that carries a price matrix: products, options and
 * addons.
 *
 * @mixin Model
 */
trait HasPrices
{
    /**
     * Declared without a generic parameter: each model narrows it to its own
     * price model, and a widened declaration here would make every one of
     * those an incompatible override.
     */
    abstract public function prices(): HasMany;

    /**
     * The price for one cycle in one currency, or null when the item is not
     * sold that way.
     *
     * Returning null rather than converting is the point: a converted price
     * changes between the page and the cart, and the customer notices.
     */
    public function priceFor(BillingCycle $cycle, string $currencyCode): ?Model
    {
        return $this->prices
            ->first(fn (Model $price): bool => $price->getAttribute('billing_cycle') === $cycle
                && $price->getAttribute('currency_code') === strtoupper($currencyCode));
    }

    public function recurringFor(BillingCycle $cycle, string $currencyCode): ?Money
    {
        $price = $this->priceFor($cycle, $currencyCode);

        return $price?->getAttribute('recurring');
    }

    public function setupFor(BillingCycle $cycle, string $currencyCode): ?Money
    {
        $price = $this->priceFor($cycle, $currencyCode);

        return $price?->getAttribute('setup');
    }

    /**
     * Whether this item can be sold in a currency at all.
     */
    public function isSellableIn(string $currencyCode): bool
    {
        return $this->prices
            ->contains(fn (Model $price): bool => $price->getAttribute('currency_code') === strtoupper($currencyCode));
    }

    /**
     * The cycles this item is sold on, in matrix order.
     *
     * @return list<BillingCycle>
     */
    public function availableCycles(string $currencyCode): array
    {
        /** @var Collection<int, Model> $prices */
        $prices = $this->prices;

        $cycles = $prices
            ->filter(fn (Model $price): bool => $price->getAttribute('currency_code') === strtoupper($currencyCode))
            ->map(fn (Model $price): BillingCycle => $price->getAttribute('billing_cycle'))
            ->unique()
            ->values()
            ->all();

        usort($cycles, fn (BillingCycle $a, BillingCycle $b): int => $a->sortOrder() <=> $b->sortOrder());

        return $cycles;
    }
}
