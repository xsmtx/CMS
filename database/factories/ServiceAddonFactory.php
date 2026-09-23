<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\AddonStatus;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceAddon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceAddon>
 */
final class ServiceAddonFactory extends Factory
{
    protected $model = ServiceAddon::class;

    public function definition(): array
    {
        return [
            // An addon always hangs off a service, so the default makes
            // one rather than leaving a row that cannot exist.
            'service_id' => fn (): string => Service::factory()->create()->id,
            'customer_id' => fn (array $attributes): string => $this->serviceFor($attributes)->customer_id,
            'organization_id' => fn (array $attributes): string => $this->serviceFor($attributes)->organization_id,
            'status' => AddonStatus::Pending->value,
            'name' => 'Extra backup space',
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 500,
            'setup_minor' => 0,
            'quantity' => 1,
            'starts_on' => now()->toDateString(),
            'next_due_on' => now()->addMonth()->toDateString(),
        ];
    }

    public function on(Service $service): self
    {
        return $this->state(fn (): array => [
            'service_id' => $service->id,
            'customer_id' => $service->customer_id,
            'organization_id' => $service->organization_id,
            'currency_code' => $service->currency_code,
        ]);
    }

    public function status(AddonStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    public function active(): self
    {
        return $this->status(AddonStatus::Active);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function serviceFor(array $attributes): Service
    {
        return Service::query()
            ->withoutGlobalScope('organization')
            ->whereKey((string) $attributes['service_id'])
            ->firstOrFail();
    }
}
