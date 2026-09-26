<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Resources\Models\ResourceMetricDay;
use App\Infrastructure\Resources\Models\ResourceNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceMetricDay>
 */
final class ResourceMetricDayFactory extends Factory
{
    protected $model = ResourceMetricDay::class;

    public function definition(): array
    {
        $value = fake()->randomFloat(3, 0, 1);

        return [
            'metric' => 'cpu.utilisation',
            'unit' => 'ratio',
            'day' => now()->toDateString(),
            'samples' => 1,
            'minimum' => $value,
            'maximum' => $value,
            'sum' => $value,
            'last' => $value,
        ];
    }

    public function forNode(ResourceNode $node): self
    {
        return $this->state(fn (): array => [
            'organization_id' => $node->organization_id,
            'resource_node_id' => $node->id,
        ]);
    }

    public function on(string $day, float $value, int $samples = 1): self
    {
        return $this->state(fn (): array => [
            'day' => $day,
            'samples' => $samples,
            'minimum' => $value,
            'maximum' => $value,
            'sum' => $value * $samples,
            'last' => $value,
        ]);
    }
}
