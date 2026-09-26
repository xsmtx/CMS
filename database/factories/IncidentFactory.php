<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\IncidentState;
use App\Infrastructure\Reliability\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
final class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        return [
            'reference' => 'INC-'.$this->faker->unique()->numerify('######'),
            'title' => 'Database failover did not complete',
            'state' => IncidentState::Investigating,
            'severity' => AlertSeverity::Critical,
            'started_at' => CarbonImmutable::now()->subMinutes(40),
            'detected_at' => CarbonImmutable::now()->subMinutes(35),
            'is_public' => false,
        ];
    }

    public function inState(IncidentState $state): self
    {
        return $this->state(fn (): array => ['state' => $state]);
    }

    public function published(): self
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }
}
