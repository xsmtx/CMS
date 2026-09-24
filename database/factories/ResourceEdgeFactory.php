<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Infrastructure\Relation;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceNode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceEdge>
 */
final class ResourceEdgeFactory extends Factory
{
    protected $model = ResourceEdge::class;

    public function definition(): array
    {
        return [
            'from_node_id' => fn (): string => ResourceNode::factory()->create()->id,
            // The container's organization, which is the rule the whole
            // boundary rests on: an edge belongs to the node that contains.
            'organization_id' => fn (array $attributes): string => $this->organizationOf(
                (string) $attributes['from_node_id'],
            ),
            'to_node_id' => fn (array $attributes): string => ResourceNode::factory()
                ->create(['organization_id' => $this->organizationOf((string) $attributes['from_node_id'])])
                ->id,
            'relation' => Relation::Contains->value,
            'source' => 'core',
            'observed_at' => CarbonImmutable::now(),
        ];
    }

    public function between(ResourceNode $container, ResourceNode $contained): self
    {
        return $this->state(fn (): array => [
            'organization_id' => $container->organization_id,
            'from_node_id' => $container->id,
            'to_node_id' => $contained->id,
        ]);
    }

    public function relating(Relation $relation): self
    {
        return $this->state(fn (): array => ['relation' => $relation->value]);
    }

    public function ended(?CarbonImmutable $at = null): self
    {
        return $this->state(fn (): array => ['ended_at' => $at ?? CarbonImmutable::now()]);
    }

    private function organizationOf(string $nodeId): string
    {
        return ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->whereKey($nodeId)
            ->firstOrFail()
            ->organization_id;
    }
}
