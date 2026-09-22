<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentMethod>
 */
final class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['customer_id']),
            'gateway' => 'stripe',
            // A token belonging to the gateway. Never a card number.
            'token' => 'pm_'.Str::lower(Str::random(24)),
            'brand' => 'visa',
            'last_four' => '4242',
            'expiry_month' => 12,
            'expiry_year' => (int) now()->addYears(2)->format('Y'),
            'label' => null,
            'is_default' => false,
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
