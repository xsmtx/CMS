<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderStatusChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusChange>
 */
final class OrderStatusChangeFactory extends Factory
{
    protected $model = OrderStatusChange::class;

    public function definition(): array
    {
        return [
            'order_id' => fn (): string => Order::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['order_id']),
            'from_status' => OrderStatus::Draft->value,
            'to_status' => OrderStatus::Pending->value,
            'actor_type' => null,
            'actor_id' => null,
            'actor_label' => null,
            'reason' => null,
            'occurred_at' => now(),
        ];
    }

    private function organizationOf(string $orderId): string
    {
        return Order::query()
            ->withoutGlobalScope('organization')
            ->whereKey($orderId)
            ->firstOrFail()
            ->organization_id;
    }
}
