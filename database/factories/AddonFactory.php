<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\CatalogStatus;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Addon>
 */
final class AddonFactory extends Factory
{
    protected $model = Addon::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'product_id' => fn (): string => Product::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['product_id']),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'status' => CatalogStatus::Active->value,
            'position' => 0,
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

    private function organizationOf(string $id): string
    {
        return Product::query()
            ->withoutGlobalScope('organization')
            ->whereKey($id)
            ->firstOrFail()
            ->organization_id;
    }
}
