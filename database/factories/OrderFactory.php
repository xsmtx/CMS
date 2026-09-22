<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            // An order belongs to the customer's organization, never to
            // whichever boundary happens to be active.
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['customer_id']),
            'contact_id' => null,
            'number' => 'ORD-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'status' => OrderStatus::Pending->value,
            'currency_code' => 'EUR',
            'subtotal_minor' => 999,
            'discount_minor' => 0,
            'setup_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 999,
            'recurring_total_minor' => 999,
            'promotion_id' => null,
            'promotion_code' => null,
            'tax_breakdown' => null,
            'tax_exemption_reason' => null,
            'risk_decision' => null,
            'risk_score' => null,
            'risk_reasons' => null,
            'risk_reviewed_at' => null,
            'risk_reviewed_by' => null,
            'terms_accepted_at' => now(),
            'terms_version' => '2026-01',
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0',
            'notes' => null,
            'placed_at' => now(),
        ];
    }

    public function forCustomer(Customer|string $customer): static
    {
        $id = $customer instanceof Customer ? $customer->id : $customer;

        return $this->state(fn (): array => [
            'customer_id' => $id,
            'organization_id' => $this->organizationOf($id),
        ]);
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Draft->value,
            'placed_at' => null,
            'number' => 'DRAFT-'.Str::lower(Str::random(6)),
        ]);
    }

    private function organizationOf(string $customerId): string
    {
        return Customer::query()
            ->withoutGlobalScope('organization')
            ->whereKey($customerId)
            ->firstOrFail()
            ->organization_id;
    }
}
