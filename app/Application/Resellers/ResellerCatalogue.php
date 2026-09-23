<?php

declare(strict_types=1);

namespace App\Application\Resellers;

use App\Application\Shared\ResolveSeller;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Resellers\Models\ResellerProduct;
use App\Support\Organizations\OrganizationContext;

/**
 * Which of the provider's products an organization may buy.
 *
 * This class exists because of a question the boundary cannot answer on its
 * own. **The catalogue belongs to the provider.** A reseller's customer is
 * nowhere near it in the tree, so a scoped read finds nothing — which is
 * correct for a customer's invoices and wrong for the shop they are standing
 * in. A reseller that could not see the provider's catalogue could not sell
 * anything at all.
 *
 * So the read escapes the boundary, deliberately and in one place, and is
 * narrowed by something else instead: the **availability rows** the provider
 * wrote for that reseller. That is the trade — one justified escape, with
 * the narrowing written down beside it, rather than a nullable
 * `reseller_id` on the products table and a clause everybody has to
 * remember.
 *
 * **Absence is a refusal.** A reseller with no rows sells nothing. Not the
 * whole catalogue — a reseller created on Friday able to offer everything
 * would be exposing a product the provider had not meant to expose, and
 * there would be no way to notice. The provider's own customers are the
 * other way round: the provider sells its own catalogue, all of it, because
 * there is nobody to hide it from.
 */
final readonly class ResellerCatalogue
{
    public function __construct(
        private ResolveSeller $sellers,
        private OrganizationContext $organizations,
    ) {}

    /**
     * One product, if this buyer's seller may sell it.
     *
     * @param  list<string>  $with  Relations to eager-load, so a caller that renders a line loads it once.
     */
    public function product(string $productId, ?string $buyerOrganizationId = null, array $with = []): ?Product
    {
        $allowed = $this->allowedIds($buyerOrganizationId);

        // Null means "no restriction" — the provider selling its own
        // catalogue. An empty list means a reseller who may sell nothing,
        // and those two must never collapse into each other.
        if ($allowed !== null && ! in_array($productId, $allowed, strict: true)) {
            return null;
        }

        return $this->organizations->withoutBoundary(
            static fn (): ?Product => Product::query()
                ->withoutGlobalScope('organization')
                ->with($with)
                ->whereKey($productId)
                ->first(),
        );
    }

    /**
     * The product ids a reseller may sell, or null when the seller is the
     * provider and there is nothing to narrow.
     *
     * @return list<string>|null
     */
    public function allowedIds(?string $buyerOrganizationId = null): ?array
    {
        $buyer = $buyerOrganizationId ?? $this->organizations->id();

        if ($buyer === null) {
            return null;
        }

        $seller = $this->sellers->forOrganization($buyer);

        if (! $this->sellers->isReseller($seller)) {
            return null;
        }

        /** @var list<string> $ids */
        $ids = $this->organizations->withoutBoundary(
            static fn (): array => ResellerProduct::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $seller)
                ->where('is_enabled', true)
                ->pluck('product_id')
                ->all(),
        );

        return $ids;
    }

    /**
     * Whether this buyer's seller may sell this product at all.
     *
     * A different question from "what does it cost", which
     * `ResolveSellingPrice` answers. A product can be available and unpriced
     * for a currency, and unavailable while the provider still prices it.
     */
    public function allows(string $productId, ?string $buyerOrganizationId = null): bool
    {
        $allowed = $this->allowedIds($buyerOrganizationId);

        return $allowed === null || in_array($productId, $allowed, strict: true);
    }
}
