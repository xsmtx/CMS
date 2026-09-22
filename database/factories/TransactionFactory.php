<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\TransactionKind;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
final class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['customer_id']),
            'invoice_id' => null,
            'payment_id' => null,
            'kind' => TransactionKind::Payment->value,
            'currency_code' => 'EUR',
            'amount_minor' => 999,
            'credit_balance_minor' => 0,
            'description' => null,
            'recorded_by' => null,
            'occurred_at' => now(),
        ];
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
