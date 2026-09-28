<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Dcim\Models\RackPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RackPosition>
 */
final class RackPositionFactory extends Factory
{
    protected $model = RackPosition::class;

    public function definition(): array
    {
        return [
            'start_unit' => 1,
            'unit_height' => 1,
            'label' => 'Patch panel',
        ];
    }

    public function at(int $startUnit, int $height = 1): self
    {
        return $this->state(fn (): array => [
            'start_unit' => $startUnit,
            'unit_height' => $height,
        ]);
    }
}
