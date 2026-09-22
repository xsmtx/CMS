<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\CatalogStatus;
use App\Domain\Catalog\ProductType;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'product_group_id' => fn (): string => ProductGroup::factory()->create()->id,
            // Owned by the same organization as its group: a product in
            // another organization's group is not a thing.
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['product_group_id']),
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => ProductType::SharedHosting->value,
            'tagline' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'features' => [fake()->sentence(3), fake()->sentence(3)],
            'status' => CatalogStatus::Active->value,
            'position' => 0,
            'stock' => null,
        ];
    }

    public function inGroup(ProductGroup|string $group): static
    {
        $id = $group instanceof ProductGroup ? $group->id : $group;

        return $this->state(fn (): array => [
            'product_group_id' => $id,
            'organization_id' => $this->organizationOf($id),
        ]);
    }

    public function ofType(ProductType $type): static
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['status' => CatalogStatus::Hidden->value]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['status' => CatalogStatus::Retired->value]);
    }

    public function soldOut(): static
    {
        return $this->state(fn (): array => ['stock' => 0]);
    }

    private function organizationOf(string $id): string
    {
        return ProductGroup::query()
            ->withoutGlobalScope('organization')
            ->whereKey($id)
            ->firstOrFail()
            ->organization_id;
    }
}
