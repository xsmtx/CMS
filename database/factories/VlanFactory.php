<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Network\Models\Vlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vlan>
 */
final class VlanFactory extends Factory
{
    protected $model = Vlan::class;

    public function definition(): array
    {
        return [
            'tag' => fake()->unique()->numberBetween(2, 4000),
            'name' => fake()->word(),
            'site' => null,
        ];
    }
}
