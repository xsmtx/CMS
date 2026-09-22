<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Provisioning\PlacementStrategy;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServerGroup>
 */
final class ServerGroupFactory extends Factory
{
    protected $model = ServerGroup::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' Group');

        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'placement_strategy' => PlacementStrategy::LeastAccounts->value,
            'region' => null,
            'notes' => null,
        ];
    }

    public function strategy(PlacementStrategy $strategy): self
    {
        return $this->state(fn (): array => ['placement_strategy' => $strategy->value]);
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }
}
