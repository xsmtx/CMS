<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Dcim\Models\PartFitting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartFitting>
 */
final class PartFittingFactory extends Factory
{
    protected $model = PartFitting::class;

    public function definition(): array
    {
        return [
            'fitted_at' => CarbonImmutable::now()->subYear(),
        ];
    }

    public function removed(): self
    {
        return $this->state(fn (): array => ['removed_at' => CarbonImmutable::now()->subMonth()]);
    }
}
