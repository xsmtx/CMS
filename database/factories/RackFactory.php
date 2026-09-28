<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Dcim\Models\Rack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rack>
 */
final class RackFactory extends Factory
{
    protected $model = Rack::class;

    public function definition(): array
    {
        return [
            'name' => 'R'.fake()->unique()->numberBetween(1, 999),
            'units' => 42,
        ];
    }

    public function of(int $units): self
    {
        return $this->state(fn (): array => ['units' => $units]);
    }
}
