<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceOption>
 */
final class ServiceOptionFactory extends Factory
{
    protected $model = ServiceOption::class;

    public function definition(): array
    {
        return [
            'service_id' => fn (): string => Service::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Service::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['service_id'])
                ->firstOrFail()
                ->organization_id,
            'group_name' => 'Control panel',
            'label' => 'cPanel',
            'value' => 'cpanel',
            'position' => 0,
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
