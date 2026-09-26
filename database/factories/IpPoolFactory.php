<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Network\IpFamily;
use App\Domain\Network\PoolPurpose;
use App\Infrastructure\Network\Models\IpPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpPool>
 */
final class IpPoolFactory extends Factory
{
    protected $model = IpPool::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'family' => IpFamily::V4->value,
            'purpose' => PoolPurpose::Customer->value,
        ];
    }

    public function v6(): self
    {
        return $this->state(fn (): array => ['family' => IpFamily::V6->value]);
    }

    public function infrastructure(): self
    {
        return $this->state(fn (): array => ['purpose' => PoolPurpose::Infrastructure->value]);
    }
}
