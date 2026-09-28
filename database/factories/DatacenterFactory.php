<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Dcim\Models\Datacenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Datacenter>
 */
final class DatacenterFactory extends Factory
{
    protected $model = Datacenter::class;

    public function definition(): array
    {
        return [
            'name' => 'DC'.fake()->unique()->numberBetween(1, 999),
            'code' => 'DC'.fake()->numberBetween(1, 99),
        ];
    }
}
