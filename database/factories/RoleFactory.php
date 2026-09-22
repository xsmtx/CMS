<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Access\RoleScope;
use App\Infrastructure\Access\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
final class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'scope' => RoleScope::Staff->value,
            'name' => $name,
            'description' => fake()->sentence(),
            'is_system' => false,
        ];
    }

    public function customerScoped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => RoleScope::Customer->value,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_system' => true,
        ]);
    }
}
