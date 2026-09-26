<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Provisioning\PlacementStrategy;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServicePlacement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServicePlacement>
 */
final class ServicePlacementFactory extends Factory
{
    protected $model = ServicePlacement::class;

    public function definition(): array
    {
        return [
            'strategy' => PlacementStrategy::Scored->value,
            'server_name' => fake()->domainWord(),
            'score' => fake()->randomFloat(4, 0, 1),
            'candidates' => 1,
            'factors' => null,
            'decided_at' => now(),
        ];
    }

    public function for_(Service $service, Server $server): self
    {
        return $this->state(fn (): array => [
            'organization_id' => $service->organization_id,
            'service_id' => $service->id,
            'server_id' => $server->id,
            'server_name' => $server->name,
        ]);
    }
}
