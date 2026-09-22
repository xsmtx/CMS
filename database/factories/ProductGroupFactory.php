<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\CatalogStatus;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductGroup>
 */
final class ProductGroupFactory extends Factory
{
    protected $model = ProductGroup::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            // A catalog belongs to whoever sells it: the active boundary
            // when there is one, otherwise the provider.
            'organization_id' => fn (): string => $this->sellingOrganization()->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'status' => CatalogStatus::Active->value,
            'position' => 0,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['status' => CatalogStatus::Hidden->value]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['status' => CatalogStatus::Retired->value]);
    }

    public function forOrganization(Organization|string $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
        ]);
    }

    private function sellingOrganization(): Organization
    {
        $boundary = app(OrganizationContext::class)->id();

        if ($boundary !== null) {
            return Organization::query()
                ->withoutGlobalScope('organization')
                ->findOrFail($boundary);
        }

        return Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->first() ?? Organization::factory()->provider()->create();
    }
}
