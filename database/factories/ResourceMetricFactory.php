<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Infrastructure\MetricKind;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceMetric>
 */
final class ResourceMetricFactory extends Factory
{
    protected $model = ResourceMetric::class;

    public function definition(): array
    {
        return [
            'resource_node_id' => fn (): string => ResourceNode::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => ResourceNode::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['resource_node_id'])
                ->firstOrFail()
                ->organization_id,
            'metric' => MetricKind::CpuUtilisation->value,
            'unit' => MetricKind::CpuUtilisation->unit()->value,
            'value' => 0.25,
            'sampled_at' => CarbonImmutable::now(),
            'source' => 'test',
        ];
    }

    /**
     * Named `against`, not `for`: `Factory::for()` is the relationship helper and
     * overriding it with a different meaning is how a factory starts lying.
     */
    public function against(ResourceNode $node): self
    {
        return $this->state(fn (): array => [
            'resource_node_id' => $node->id,
            'organization_id' => $node->organization_id,
        ]);
    }

    public function of(MetricKind $metric, float $value): self
    {
        return $this->state(fn (): array => [
            'metric' => $metric->value,
            'unit' => $metric->unit()->value,
            'value' => $value,
        ]);
    }

    public function sampledAt(CarbonImmutable $at, ?int $staleAfter = null): self
    {
        return $this->state(fn (): array => [
            'sampled_at' => $at,
            'stale_after_seconds' => $staleAfter,
        ]);
    }
}
