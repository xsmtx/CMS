<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\LineKind;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
final class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    public function definition(): array
    {
        return [
            'cart_id' => fn (): string => Cart::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['cart_id']),
            'parent_id' => null,
            'kind' => LineKind::Product->value,
            'product_id' => fn (): string => Product::factory()->create()->id,
            'addon_id' => null,
            'billing_cycle' => BillingCycle::Monthly->value,
            'quantity' => 1,
            'domain' => null,
            'domain_tld' => null,
            'domain_years' => null,
            'domain_registration_minor' => null,
            'position' => 0,
        ];
    }

    public function inCart(Cart|string $cart): static
    {
        $id = $cart instanceof Cart ? $cart->id : $cart;

        return $this->state(fn (): array => [
            'cart_id' => $id,
            'organization_id' => $this->organizationOf($id),
        ]);
    }

    public function forProduct(Product|string $product): static
    {
        return $this->state(fn (): array => [
            'product_id' => $product instanceof Product ? $product->id : $product,
        ]);
    }

    public function cycle(BillingCycle $cycle): static
    {
        return $this->state(fn (): array => ['billing_cycle' => $cycle->value]);
    }

    public function domain(string $domain, int $years = 1, int $registrationMinor = 1200): static
    {
        $labels = explode('.', $domain);
        array_shift($labels);

        return $this->state(fn (): array => [
            'kind' => LineKind::Domain->value,
            'product_id' => null,
            'billing_cycle' => null,
            'domain' => $domain,
            'domain_tld' => implode('.', $labels),
            'domain_years' => $years,
            'domain_registration_minor' => $registrationMinor,
        ]);
    }

    private function organizationOf(string $cartId): string
    {
        return Cart::query()
            ->withoutGlobalScope('organization')
            ->whereKey($cartId)
            ->firstOrFail()
            ->organization_id;
    }
}
