<?php

declare(strict_types=1);

namespace App\Application\Resellers;

use App\Application\Shared\ResolveSeller;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Resellers\Models\ResellerPrice;
use App\Infrastructure\Resellers\Models\ResellerProduct;
use App\Support\Organizations\OrganizationContext;

/**
 * What the customer in front of us pays.
 *
 * The provider's matrix is the provider's. When the seller is a reseller,
 * the number the customer sees is the reseller's, and this is the one place
 * that works out which.
 *
 * **It resolves; it does not merge** — the same rule themes got in ADR 0037.
 * In order:
 *
 * 1. An **exact reseller price** for this product, cycle and currency. An
 *    operator who typed a number meant that number.
 * 2. The provider's price with the reseller's **margin** applied.
 * 3. The provider's price, when the reseller set no margin.
 *
 * One winner, never an average of two. And nothing is invented: a product
 * the provider does not sell for this cycle and currency has no price for a
 * reseller either, because a markup on nothing is nothing.
 *
 * **The arithmetic is integer.** A margin is a decimal string and is applied
 * through `Money::percentage()`, which works in minor units — a float in the
 * middle is how 1999 becomes 1998.9999999999998, and then a price list
 * nobody can reconcile.
 *
 * The result is a price like any other, which means the order line copies it
 * (ADR 0021). A margin changed next month never moves a document that
 * already exists.
 */
final readonly class ResolveSellingPrice
{
    public function __construct(
        private ResolveSeller $sellers,
        private OrganizationContext $organizations,
    ) {}

    /**
     * The one-off price a buyer under this organization pays.
     */
    public function setup(Product $product, BillingCycle $cycle, string $currency, ?string $buyerOrganizationId = null): ?Money
    {
        return $this->resolve($product, $cycle, $currency, $buyerOrganizationId)?->setup;
    }

    /**
     * The recurring price a buyer under this organization pays.
     */
    public function recurring(Product $product, BillingCycle $cycle, string $currency, ?string $buyerOrganizationId = null): ?Money
    {
        return $this->resolve($product, $cycle, $currency, $buyerOrganizationId)?->recurring;
    }

    /**
     * Both amounts, so a caller that needs the pair asks once.
     */
    public function resolve(
        Product $product,
        BillingCycle $cycle,
        string $currency,
        ?string $buyerOrganizationId = null,
    ): ?SellingPrice {
        $currency = strtoupper($currency);

        $providerRecurring = $product->recurringFor($cycle, $currency);

        // Absence means not sold. A reseller cannot mark up a price the
        // provider never set, and inventing one here would be a product on
        // sale that the cart then refuses.
        if (! $providerRecurring instanceof Money) {
            return null;
        }

        $providerSetup = $product->setupFor($cycle, $currency) ?? Money::zero($currency);
        $seller = $this->sellerFor($buyerOrganizationId);

        if ($seller === null) {
            return new SellingPrice($providerRecurring, $providerSetup);
        }

        $exact = $this->exactPrice($seller, $product->id, $cycle, $currency);

        if ($exact instanceof ResellerPrice) {
            return new SellingPrice($exact->recurring, $exact->setup);
        }

        $margin = $this->marginOf($seller, $product->id);

        if ($margin === null) {
            return new SellingPrice($providerRecurring, $providerSetup);
        }

        return new SellingPrice(
            $providerRecurring->plus($providerRecurring->percentage($margin)),
            // Setup is marked up too: it is money the reseller collects on
            // the provider's behalf, and a reseller whose recurring price
            // carried a margin and whose setup fee did not would be selling
            // the setup at cost without having said so.
            $providerSetup->plus($providerSetup->percentage($margin)),
            $margin,
        );
    }

    /**
     * The markup that applies to a product, for a caller pricing its extras.
     *
     * An addon or a configurable option is sold **with** a plan, so it
     * carries the plan's markup. A reseller who marked a plan up 20% and
     * whose extras went out at cost would be discounting without having
     * said so, and the difference would only show up in a margin report
     * months later.
     *
     * Null when the provider is selling, or when the reseller set no
     * markup — the caller then uses the catalogue's number unchanged.
     */
    public function marginFor(Product $product, ?string $buyerOrganizationId = null): ?string
    {
        $seller = $this->sellerFor($buyerOrganizationId);

        return $seller === null ? null : $this->marginOf($seller, $product->id);
    }

    /**
     * Apply a markup that may not exist.
     *
     * Here rather than at each call site: three places price an extra, and
     * three copies of "add the percentage unless it is null" is three
     * chances to write one that rounds differently.
     */
    public function markUp(Money $amount, ?string $marginPercent): Money
    {
        return $marginPercent === null ? $amount : $amount->plus($amount->percentage($marginPercent));
    }

    /**
     * The reseller selling to this buyer, or null when the provider is.
     *
     * Read through `ResolveSeller`, which is the one place that answers "who
     * sells to this organization" — three private copies of a boundary
     * escape is three chances to write one without the narrowing that makes
     * it safe.
     */
    private function sellerFor(?string $buyerOrganizationId): ?string
    {
        $buyer = $buyerOrganizationId ?? $this->organizations->id();

        if ($buyer === null) {
            return null;
        }

        $seller = $this->sellers->forOrganization($buyer);

        return $this->sellers->isReseller($seller) ? $seller : null;
    }

    private function exactPrice(string $seller, string $productId, BillingCycle $cycle, string $currency): ?ResellerPrice
    {
        // Executed inside the callback: a builder handed back out of
        // `withoutBoundary()` is scoped again by the time anybody calls
        // `first()` on it, and the symptom is an empty result with no error.
        return $this->organizations->withoutBoundary(
            static fn (): ?ResellerPrice => ResellerPrice::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $seller)
                ->where('product_id', $productId)
                ->where('billing_cycle', $cycle->value)
                ->where('currency_code', $currency)
                ->first(),
        );
    }

    /**
     * The markup this reseller put on this product, as a decimal string.
     *
     * Null when they set none, **and null when they may not sell it at all**
     * — but the caller asked for a price, and whether a reseller may offer a
     * product is a different question with a different answer.
     * `ResellerCatalogue` asks that one.
     */
    private function marginOf(string $seller, string $productId): ?string
    {
        $row = $this->organizations->withoutBoundary(
            static fn (): ?ResellerProduct => ResellerProduct::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $seller)
                ->where('product_id', $productId)
                ->first(),
        );

        if (! $row instanceof ResellerProduct) {
            return null;
        }

        $margin = $row->margin_percent;

        return $margin === null || $margin === '' ? null : $margin;
    }
}
