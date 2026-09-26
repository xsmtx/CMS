<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Infrastructure\Network\DdosVector;
use App\Infrastructure\Network\Models\DdosEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DdosEvent>
 */
final class DdosEventFactory extends Factory
{
    protected $model = DdosEvent::class;

    public function definition(): array
    {
        return [
            'source' => 'scrubbing',
            'reference' => (string) $this->faker->unique()->numerify('atk-######'),
            'target_address' => '198.51.100.7',
            'started_at' => CarbonImmutable::now()->subMinutes(20),
            'ended_at' => CarbonImmutable::now()->subMinutes(5),
            'peak_gbps' => '41.200',
            'peak_mpps' => '6.400',
            'vectors' => [DdosVector::NtpReflection->value],
            'mitigation' => 'Diverted to scrubbing',
        ];
    }

    /** Still going, which is the state an operator opens the screen for. */
    public function running(): self
    {
        return $this->state(fn (): array => [
            'started_at' => CarbonImmutable::now()->subMinutes(4),
            'ended_at' => null,
        ]);
    }
}
