<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Provisioning\OperationOutcome;
use App\Domain\Provisioning\ServiceOperation;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceEvent>
 */
final class ServiceEventFactory extends Factory
{
    protected $model = ServiceEvent::class;

    public function definition(): array
    {
        return [
            'service_id' => fn (): string => Service::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Service::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['service_id'])
                ->firstOrFail()
                ->organization_id,
            'operation' => ServiceOperation::Create->value,
            'outcome' => OperationOutcome::Succeeded->value,
            'actor_label' => null,
            'message' => null,
            'metadata' => null,
            'correlation_id' => null,
            'occurred_at' => now(),
        ];
    }

    public function forService(Service $service): self
    {
        return $this->state(fn (): array => [
            'service_id' => $service->id,
            'organization_id' => $service->organization_id,
        ]);
    }
}
