<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Intelligence\ReconciliationClass;
use App\Infrastructure\Intelligence\Models\ReconciliationFinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationFinding>
 */
final class ReconciliationFindingFactory extends Factory
{
    protected $model = ReconciliationFinding::class;

    public function definition(): array
    {
        return [
            'subject_label' => 'acme-hosting',
            'resource' => 'service',
            'class' => ReconciliationClass::Drift,
            'field' => 'status',
            'expected' => 'Active',
            'found' => 'Suspended',
            'source' => 'service',
            'first_seen_at' => CarbonImmutable::now()->subDays(2),
            'last_seen_at' => CarbonImmutable::now(),
            'cleared_token' => '',
        ];
    }

    public function of(ReconciliationClass $class): self
    {
        return $this->state(fn (): array => ['class' => $class]);
    }

    public function cleared(): self
    {
        return $this->state(fn (array $attributes): array => [
            'cleared_at' => CarbonImmutable::now(),
            'cleared_token' => (string) ($attributes['id'] ?? 'cleared'),
        ]);
    }
}
