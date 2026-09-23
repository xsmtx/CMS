<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Crm\CancellationStatus;
use App\Domain\Crm\CancellationType;
use App\Infrastructure\Crm\Models\CancellationRequest;
use App\Infrastructure\Provisioning\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CancellationRequest>
 */
final class CancellationRequestFactory extends Factory
{
    protected $model = CancellationRequest::class;

    public function definition(): array
    {
        return [
            'service_id' => fn (): string => Service::factory()->create()->id,
            'customer_id' => fn (array $attributes): string => $this->serviceFor($attributes)->customer_id,
            'organization_id' => fn (array $attributes): string => $this->serviceFor($attributes)->organization_id,
            'type' => CancellationType::EndOfTerm->value,
            'status' => CancellationStatus::Pending->value,
            'reason' => 'Moving to another provider.',
            'requested_by_label' => 'Zeynep Kaya',
            'requested_at' => now(),
        ];
    }

    public function on(Service $service): self
    {
        return $this->state(fn (): array => [
            'service_id' => $service->id,
            'customer_id' => $service->customer_id,
            'organization_id' => $service->organization_id,
        ]);
    }

    public function immediate(): self
    {
        return $this->state(fn (): array => ['type' => CancellationType::Immediate->value]);
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
