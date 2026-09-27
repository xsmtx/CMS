<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Reliability\AlertSeverity;
use App\Domain\Security\AbuseKind;
use App\Domain\Security\AbuseState;
use App\Infrastructure\Security\Models\AbuseCase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbuseCase>
 */
final class AbuseCaseFactory extends Factory
{
    protected $model = AbuseCase::class;

    public function definition(): array
    {
        return [
            'reference' => 'ABU-'.$this->faker->unique()->numerify('######'),
            'kind' => AbuseKind::Spam,
            'state' => AbuseState::Open,
            'severity' => AlertSeverity::Warning,
            'source' => 'spamtrap.example.net',
            'summary' => 'Outbound spam reported from a shared address',
            'subject_type' => 'ip',
            'subject_value' => '198.51.100.7',
            // Yesterday, because the whole point of the column is that it is
            // not the moment the complaint arrived.
            'occurred_at' => CarbonImmutable::now()->subDay(),
            'reported_at' => CarbonImmutable::now(),
        ];
    }

    public function inState(AbuseState $state): self
    {
        return $this->state(fn (): array => ['state' => $state]);
    }

    public function ofKind(AbuseKind $kind): self
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    public function about(string $type, string $value): self
    {
        return $this->state(fn (): array => [
            'subject_type' => $type,
            'subject_value' => $value,
        ]);
    }
}
