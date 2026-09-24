<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Infrastructure\ResourceKind;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceNode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ResourceNode>
 */
final class ResourceNodeFactory extends Factory
{
    protected $model = ResourceNode::class;

    public function definition(): array
    {
        $key = 'node-'.Str::lower(Str::random(6));

        return [
            'organization_id' => fn (): string => Organization::factory()->create()->id,
            'kind' => ResourceKind::Server,
            'node_key' => $key,
            'label' => $key,
            'source' => 'core',
            'health' => ResourceNode::HealthUnknown,
            'discovered_at' => CarbonImmutable::now(),
            'last_seen_at' => CarbonImmutable::now(),
        ];
    }

    public function of(string $kind): self
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    public function in(Organization $organization): self
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }

    public function keyed(string $nodeKey): self
    {
        return $this->state(fn (): array => ['node_key' => $nodeKey, 'label' => $nodeKey]);
    }

    public function retired(): self
    {
        return $this->state(fn (): array => ['retired_at' => CarbonImmutable::now()]);
    }
}
