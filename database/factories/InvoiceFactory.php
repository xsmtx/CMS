<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['customer_id']),
            'order_id' => null,
            'number' => 'INV-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'status' => InvoiceStatus::Unpaid->value,
            'currency_code' => 'EUR',
            'bill_to_name' => fake()->name(),
            'bill_to_company' => null,
            'bill_to_tax_id' => null,
            'bill_to_address' => null,
            'bill_to_country' => 'TR',
            'bill_to_email' => fake()->safeEmail(),
            'subtotal_minor' => 999,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 999,
            'paid_minor' => 0,
            'tax_breakdown' => null,
            'tax_exemption_reason' => null,
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(14)->toDateString(),
            'paid_at' => null,
            'cancelled_at' => null,
            'is_proforma' => false,
            'notes' => null,
            'terms' => null,
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

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Draft->value,
            'number' => 'DRAFT-'.fake()->unique()->numberBetween(1, 999999),
            'issued_on' => null,
            'due_on' => null,
        ]);
    }

    public function status(InvoiceStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    public function totalling(int $minor, string $currency = 'EUR'): static
    {
        return $this->state(fn (): array => [
            'currency_code' => strtoupper($currency),
            'subtotal_minor' => $minor,
            'total_minor' => $minor,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Overdue->value,
            'due_on' => now()->subDays(7)->toDateString(),
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
