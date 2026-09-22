<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Crm\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomFieldValue>
 */
final class CustomFieldValueFactory extends Factory
{
    protected $model = CustomFieldValue::class;

    public function definition(): array
    {
        return [
            'value' => fake()->word(),
        ];
    }
}
