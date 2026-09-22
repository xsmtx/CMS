<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\CatalogStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What the storefront shows, for one currency.
 *
 * A product appears only when it has a price row in the requested currency.
 * Nothing is converted: a price that changes between the listing and the
 * cart is a price the customer will notice, and rightly not trust.
 *
 * Scoped to exactly one organization rather than to the boundary subtree. A
 * boundary answers "what may this actor reach", which for a provider
 * includes every reseller under it; a storefront answers "what does this
 * brand sell", which does not.
 */
final readonly class StorefrontCatalog
{
    public function __construct(private OrganizationContext $context) {}

    /**
     * Listed groups, each with the products that can be bought in this
     * currency. Groups that end up empty are dropped rather than rendered
     * as a heading with nothing under it.
     *
     * @return Collection<int, ProductGroup>
     */
    public function groups(string $currencyCode): Collection
    {
        $currency = strtoupper($currencyCode);

        return ProductGroup::query()
            ->where('status', CatalogStatus::Active->value)
            ->tap($this->ownedByStorefront(...))
            ->with(['products' => function ($query) use ($currency): void {
                $query->where('status', CatalogStatus::Active->value)
                    ->whereHas('prices', fn ($prices) => $prices->where('currency_code', $currency))
                    ->with(['prices' => fn ($prices) => $prices->where('currency_code', $currency)])
                    ->orderBy('position')
                    ->orderBy('name');
            }])
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->filter(fn (ProductGroup $group): bool => $group->products->isNotEmpty())
            ->values();
    }

    /**
     * One product, by slug, including the hidden ones.
     *
     * Hidden means "not in the menu but reachable by anyone holding the
     * link", which is how an operator runs an unlisted offer.
     */
    public function product(string $slug, string $currencyCode): ?Product
    {
        $currency = strtoupper($currencyCode);

        $product = Product::query()
            ->orderable()
            ->tap($this->ownedByStorefront(...))
            ->where('slug', $slug)
            ->with([
                'group',
                'prices' => fn ($prices) => $prices->where('currency_code', $currency),
                'optionGroups.options.prices' => fn ($prices) => $prices->where('currency_code', $currency),
                'addons.prices' => fn ($prices) => $prices->where('currency_code', $currency),
            ])
            ->first();

        return $product?->isSellableIn($currency) === true ? $product : null;
    }

    /**
     * The headline "from" price: the cheapest recurring amount per month.
     *
     * Comparing an annual price against a monthly one directly would make
     * the annual plan look expensive, so each cycle is reduced to its
     * monthly equivalent before they are compared. The price shown is still
     * the real one for its cycle; only the comparison is normalised.
     *
     * @return array{money: Money, cycle: BillingCycle}|null
     */
    public function startingPrice(Product $product, string $currencyCode): ?array
    {
        $currency = strtoupper($currencyCode);
        $best = null;

        foreach ($product->availableCycles($currency) as $cycle) {
            $money = $product->recurringFor($cycle, $currency);

            if ($money === null || ! $cycle->isRecurring()) {
                continue;
            }

            $perMonth = intdiv($money->minorUnits, max($cycle->months(), 1));

            if ($best === null || $perMonth < $best['per_month']) {
                $best = ['per_month' => $perMonth, 'money' => $money, 'cycle' => $cycle];
            }
        }

        if ($best === null) {
            // Nothing recurring: a one-time product still needs a price on
            // the card.
            $money = $product->recurringFor(BillingCycle::OneTime, $currency);

            return $money === null ? null : ['money' => $money, 'cycle' => BillingCycle::OneTime];
        }

        return ['money' => $best['money'], 'cycle' => $best['cycle']];
    }

    /**
     * Restrict a query to the storefront's own organization.
     *
     * @param  Builder<covariant Model>  $query
     */
    private function ownedByStorefront(Builder $query): void
    {
        $organizationId = $this->context->id();

        if ($organizationId !== null) {
            $query->where($query->qualifyColumn('organization_id'), $organizationId);
        }
    }
}
