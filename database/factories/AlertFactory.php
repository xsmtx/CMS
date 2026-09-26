<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertState;
use App\Infrastructure\Reliability\Models\Alert;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
final class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        return [
            'subject_key' => 'web-1.dc2',
            'subject_label' => 'web-1',
            'state' => AlertState::Raised,
            'severity' => AlertSeverity::Warning,
            'observed' => '94.0%',
            'occurrences' => 1,
            'first_seen_at' => CarbonImmutable::now()->subMinutes(30),
            'last_seen_at' => CarbonImmutable::now(),
            'dedupe_token' => '',
        ];
    }
}
