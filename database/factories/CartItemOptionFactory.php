<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Ordering\Models\CartItem;
use App\Infrastructure\Ordering\Models\CartItemOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItemOption>
 */
final class CartItemOptionFactory extends Factory
{
    protected $model = CartItemOption::class;

    public function definition(): array
    {
        return [
            'cart_item_id' => fn (): string => CartItem::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['cart_item_id']),
            'option_group_id' => fn (): string => Option::factory()->create()->option_group_id,
            'option_id' => null,
            'quantity' => 1,
        ];
    }

    public function choosing(Option $option): static
    {
        return $this->state(fn (): array => [
            'option_group_id' => $option->option_group_id,
            'option_id' => $option->id,
        ]);
    }

    private function organizationOf(string $itemId): string
    {
        return CartItem::query()
            ->withoutGlobalScope('organization')
            ->whereKey($itemId)
            ->firstOrFail()
            ->organization_id;
    }
}
