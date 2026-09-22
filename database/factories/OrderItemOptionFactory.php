<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Ordering\Models\OrderItemOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItemOption>
 */
final class OrderItemOptionFactory extends Factory
{
    protected $model = OrderItemOption::class;

    public function definition(): array
    {
        return [
            'order_item_id' => fn (): string => OrderItem::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['order_item_id']),
            'option_group_id' => null,
            'option_id' => null,
            'group_name' => 'Control panel',
            'group_key' => 'control_panel',
            'label' => 'cPanel',
            'value' => 'cpanel',
            'quantity' => 1,
            'currency_code' => 'EUR',
            'recurring_minor' => 500,
            'setup_minor' => 0,
        ];
    }

    private function organizationOf(string $itemId): string
    {
        return OrderItem::query()
            ->withoutGlobalScope('organization')
            ->whereKey($itemId)
            ->firstOrFail()
            ->organization_id;
    }
}
