<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Infrastructure\MetricKind;
use App\Domain\Reliability\AlertComparison;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertSubject;
use App\Infrastructure\Reliability\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
final class AlertRuleFactory extends Factory
{
    protected $model = AlertRule::class;

    public function definition(): array
    {
        return [
            'name' => 'Disk above 90%',
            'subject' => AlertSubject::Metric,
            'target' => MetricKind::DiskUsed->value,
            'comparison' => AlertComparison::Above,
            // Parts per million: 90% of a ratio is 0.9.
            'threshold_ppm' => 900_000,
            'for_minutes' => 0,
            'severity' => AlertSeverity::Warning,
            'enabled' => true,
            'notify' => true,
        ];
    }

    public function about(AlertSubject $subject, ?string $target = null): self
    {
        return $this->state(fn (): array => [
            'subject' => $subject,
            'target' => $target,
            'comparison' => $subject->isNumeric() ? AlertComparison::Above : null,
            'threshold_ppm' => $subject->isNumeric() ? 900_000 : null,
        ]);
    }

    public function severity(AlertSeverity $severity): self
    {
        return $this->state(fn (): array => ['severity' => $severity]);
    }
}
