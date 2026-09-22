<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Access\RoleScope;
use App\Infrastructure\Access\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
final class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        $group = fake()->unique()->word();

        return [
            'slug' => $group.'.'.Str::lower(Str::random(8)).'.view',
            'group' => $group,
            'scope' => RoleScope::Staff->value,
            'is_high_risk' => false,
            'module' => null,
            'orphaned_at' => null,
        ];
    }

    public function orphaned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'orphaned_at' => now(),
        ]);
    }
}
