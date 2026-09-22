<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\BillingCycle;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPrice>
 */
final class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    public function definition(): array
    {
        return [
            'product_id' => fn (): string => Product::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['product_id']),
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'EUR',
            // Minor units. 9.99 and 0.00, not 9.99 and 0.
            'recurring_minor' => 999,
            'setup_minor' => 0,
        ];
    }

    public function forProduct(Product|string $product): static
    {
        $id = $product instanceof Product ? $product->id : $product;

        return $this->state(fn (): array => [
            'product_id' => $id,
            'organization_id' => $this->organizationOf($id),
        ]);
    }

    public function cycle(BillingCycle $cycle): static
    {
        return $this->state(fn (): array => ['billing_cycle' => $cycle->value]);
    }

    public function currency(string $code): static
    {
        return $this->state(fn (): array => ['currency_code' => strtoupper($code)]);
    }

    public function amounts(int $recurringMinor, int $setupMinor = 0): static
    {
        return $this->state(fn (): array => [
            'recurring_minor' => $recurringMinor,
            'setup_minor' => $setupMinor,
        ]);
    }

    private function organizationOf(string $id): string
    {
        return Product::query()
            ->withoutGlobalScope('organization')
            ->whereKey($id)
            ->firstOrFail()
            ->organization_id;
    }
}
