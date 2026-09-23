<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resellers\Models\ResellerProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResellerProduct>
 */
final class ResellerProductFactory extends Factory
{
    protected $model = ResellerProduct::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->reseller()->create()->id,
            'product_id' => fn (): string => Product::factory()->create()->id,
            'margin_percent' => null,
            'is_enabled' => true,
        ];
    }

    public function forReseller(Organization|string $reseller): static
    {
        $id = $reseller instanceof Organization ? $reseller->id : $reseller;

        return $this->state(fn (): array => ['organization_id' => $id]);
    }

    public function forProduct(Product|string $product): static
    {
        $id = $product instanceof Product ? $product->id : $product;

        return $this->state(fn (): array => ['product_id' => $id]);
    }

    public function withMargin(string $percent): static
    {
        return $this->state(fn (): array => ['margin_percent' => $percent]);
    }
}
