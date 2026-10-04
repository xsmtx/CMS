<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\UpgradeState;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceUpgrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceUpgrade>
 */
final class ServiceUpgradeFactory extends Factory
{
    protected $model = ServiceUpgrade::class;

    public function definition(): array
    {
        return [
            'service_id' => fn (): string => Service::factory()->create()->id,
            /*
             * Read from the service rather than created on its own: an upgrade
             * in a different organization from the service it is about is a
             * row the boundary would hide from one side and show on the other.
             */
            'organization_id' => fn (array $attributes): string => Service::query()
                ->whereKey($attributes['service_id'])
                ->firstOrFail()
                ->organization_id,
            'state' => UpgradeState::AwaitingPayment->value,
            'from_product_name' => 'Starter',
            'from_cycle' => BillingCycle::Monthly->value,
            'from_recurring_minor' => 9_99,
            'to_product_id' => fn (): string => Product::factory()->create()->id,
            'to_product_name' => 'Business',
            'to_cycle' => BillingCycle::Monthly->value,
            'to_recurring_minor' => 29_99,
            'currency_code' => 'EUR',
            'credit_minor' => 5_00,
            'charge_minor' => 15_00,
            'difference_minor' => 10_00,
            'days_remaining' => 15,
            'term_days' => 30,
        ];
    }

    public function inState(UpgradeState $state): self
    {
        return $this->state(fn (): array => ['state' => $state->value]);
    }

    /**
     * A move to a cheaper plan: the difference is negative, which is the whole
     * point of the column being signed.
     */
    public function downgrade(): self
    {
        return $this->state(fn (): array => [
            'credit_minor' => 15_00,
            'charge_minor' => 5_00,
            'difference_minor' => -10_00,
        ]);
    }
}
