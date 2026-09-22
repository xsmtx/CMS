<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\LineKind;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
final class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id' => fn (): string => Order::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['order_id']),
            'parent_id' => null,
            'kind' => LineKind::Product->value,
            'product_id' => null,
            'addon_id' => null,
            'name' => 'Starter Plan',
            'group_name' => 'Shared Hosting',
            'description' => null,
            'billing_cycle' => BillingCycle::Monthly->value,
            'quantity' => 1,
            'currency_code' => 'EUR',
            'unit_recurring_minor' => 999,
            'unit_setup_minor' => 0,
            'line_recurring_minor' => 999,
            'line_setup_minor' => 0,
            'line_discount_minor' => 0,
            'line_total_minor' => 999,
            'domain' => null,
            'domain_tld' => null,
            'domain_years' => null,
            'position' => 0,
        ];
    }

    public function forOrder(Order|string $order): static
    {
        $id = $order instanceof Order ? $order->id : $order;

        return $this->state(fn (): array => [
            'order_id' => $id,
            'organization_id' => $this->organizationOf($id),
        ]);
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
