<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Ordering\Exceptions\InvalidCartItem;
use App\Application\Ordering\Exceptions\PriceUnavailable;
use App\Application\Ordering\Exceptions\ProductNotOrderable;
use App\Application\Resellers\ResellerCatalogue;
use App\Domain\Catalog\OptionType;
use App\Domain\Domains\DomainName;
use App\Domain\Ordering\LineKind;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\CartItem;
use Illuminate\Support\Facades\DB;

/**
 * Puts a configured product into a cart.
 *
 * Everything is checked against the catalog rather than trusted from the
 * form: that an option belongs to this product, that a required question
 * was answered, that the plan is actually sold on the chosen cycle in the
 * cart's currency. A form can be edited; the catalog is the truth.
 */
final readonly class AddToCart
{
    public function __construct(private ResellerCatalogue $catalogue) {}

    public function handle(Cart $cart, AddToCartRequest $request): CartItem
    {
        // Through the reseller catalogue, not a scoped `firstOrFail()`. The
        // catalogue belongs to the provider, so a reseller's customer is
        // nowhere near it in the tree and a scoped read finds nothing —
        // correct for their invoices, wrong for the shop they are standing
        // in. `ResellerCatalogue` is the one place that escapes the boundary
        // for this, and it narrows by what the provider said the reseller
        // may sell.
        $product = $this->catalogue->product(
            $request->productId,
            $cart->organization_id,
            ['prices', 'optionGroups.options', 'addons.prices'],
        );

        if (! $product instanceof Product) {
            throw ProductNotOrderable::notAvailable($request->productId);
        }

        $this->assertOrderable($product);
        $this->assertSold($product, $request, $cart->currency_code);
        $this->assertDomain($product, $request);

        return DB::transaction(function () use ($cart, $product, $request): CartItem {
            $position = (int) $cart->allItems()->max('position');

            $item = $cart->allItems()->create([
                'organization_id' => $cart->organization_id,
                'kind' => LineKind::Product->value,
                'product_id' => $product->id,
                'billing_cycle' => $request->cycle->value,
                'quantity' => max($request->quantity, 1),
                'domain' => $request->domain === null ? null : DomainName::parse($request->domain)->value,
                'position' => $position + 1,
            ]);

            $this->attachOptions($item, $product, $request);
            $this->attachAddons($cart, $item, $product, $request);

            return $item;
        });
    }

    private function assertOrderable(Product $product): void
    {
        if (! $product->status->isOrderable()) {
            throw ProductNotOrderable::retired($product->name);
        }

        if ($product->isSoldOut()) {
            throw ProductNotOrderable::soldOut($product->name);
        }
    }

    private function assertSold(Product $product, AddToCartRequest $request, string $currency): void
    {
        if ($product->recurringFor($request->cycle, $currency) === null) {
            throw PriceUnavailable::for($product->name, $request->cycle, $currency);
        }
    }

    /**
     * A product whose type needs a hostname cannot be set up without one,
     * so the cart refuses the line rather than letting provisioning find
     * out in Phase 6.
     */
    private function assertDomain(Product $product, AddToCartRequest $request): void
    {
        if ($product->requires_domain && ($request->domain === null || trim($request->domain) === '')) {
            throw InvalidCartItem::domainRequired();
        }
    }

    private function attachOptions(CartItem $item, Product $product, AddToCartRequest $request): void
    {
        foreach ($product->optionGroups as $group) {
            $choice = $request->options[$group->id] ?? null;

            if ($choice === null) {
                if ($group->is_required) {
                    throw InvalidCartItem::optionRequired($group->name);
                }

                continue;
            }

            $optionId = $choice['option_id'] ?? null;
            $quantity = max((int) ($choice['quantity'] ?? 1), $group->type === OptionType::Quantity ? 0 : 1);

            if ($group->type === OptionType::Quantity) {
                if ($quantity < $group->min_quantity) {
                    throw InvalidCartItem::optionRequired($group->name);
                }

                if ($group->max_quantity !== null && $quantity > $group->max_quantity) {
                    throw InvalidCartItem::optionRequired($group->name);
                }

                $optionId = null;
            } else {
                $this->assertOptionBelongs($group, $optionId);
            }

            $item->options()->create([
                'organization_id' => $item->organization_id,
                'option_group_id' => $group->id,
                'option_id' => $optionId,
                'quantity' => $quantity,
            ]);
        }
    }

    private function assertOptionBelongs(OptionGroup $group, ?string $optionId): void
    {
        if ($optionId === null) {
            if ($group->is_required) {
                throw InvalidCartItem::optionRequired($group->name);
            }

            return;
        }

        $belongs = $group->options->contains(fn (Option $option): bool => $option->id === $optionId);

        if (! $belongs) {
            throw InvalidCartItem::optionNotOnProduct();
        }
    }

    private function attachAddons(Cart $cart, CartItem $item, Product $product, AddToCartRequest $request): void
    {
        foreach ($request->addonIds as $index => $addonId) {
            $addon = $product->addons->first(fn (Addon $candidate): bool => $candidate->id === $addonId);

            if (! $addon instanceof Addon) {
                throw InvalidCartItem::addonNotOnProduct();
            }

            if (! $addon->status->isOrderable()) {
                throw ProductNotOrderable::retired($addon->name);
            }

            // An addon is billed on the cycle of the product it was bought
            // with, so the line carries that rather than a cycle of its own.
            if ($addon->recurringFor($request->cycle, $cart->currency_code) === null) {
                throw PriceUnavailable::for($addon->name, $request->cycle, $cart->currency_code);
            }

            $cart->allItems()->create([
                'organization_id' => $cart->organization_id,
                'parent_id' => $item->id,
                'kind' => LineKind::Addon->value,
                'addon_id' => $addon->id,
                'billing_cycle' => $request->cycle->value,
                'quantity' => 1,
                'position' => $item->position + $index + 1,
            ]);
        }
    }
}
