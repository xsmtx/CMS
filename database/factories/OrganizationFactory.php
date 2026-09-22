<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'parent_id' => null,
            'type' => OrganizationType::Customer->value,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ];
    }

    public function provider(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => OrganizationType::Provider->value,
            'parent_id' => null,
        ]);
    }

    public function reseller(Organization|string|null $parent = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => OrganizationType::Reseller->value,
            'parent_id' => $parent instanceof Organization ? $parent->id : $parent,
        ]);
    }

    public function customerOf(Organization|string $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => OrganizationType::Customer->value,
            'parent_id' => $parent instanceof Organization ? $parent->id : $parent,
        ]);
    }
}
