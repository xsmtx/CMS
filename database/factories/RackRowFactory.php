<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Dcim\Models\RackRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RackRow>
 */
final class RackRowFactory extends Factory
{
    protected $model = RackRow::class;

    public function definition(): array
    {
        return [
            'name' => 'Row '.fake()->unique()->randomLetter(),
        ];
    }
}
