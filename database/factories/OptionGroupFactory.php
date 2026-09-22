<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\OptionType;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OptionGroup>
 */
final class OptionGroupFactory extends Factory
{
    protected $model = OptionGroup::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'product_id' => fn (): string => Product::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['product_id']),
            'name' => $name,
            'key' => Str::slug($name, '_'),
            'type' => OptionType::Select->value,
            'is_required' => false,
            'min_quantity' => 0,
            'max_quantity' => null,
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

    public function ofType(OptionType $type): static
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }

    public function quantity(int $min = 0, ?int $max = null): static
    {
        return $this->state(fn (): array => [
            'type' => OptionType::Quantity->value,
            'min_quantity' => $min,
            'max_quantity' => $max,
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
