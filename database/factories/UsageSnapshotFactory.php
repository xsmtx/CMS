<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\UsageUnit;
use App\Infrastructure\Billing\Models\UsageSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageSnapshot>
 */
final class UsageSnapshotFactory extends Factory
{
    protected $model = UsageSnapshot::class;

    public function definition(): array
    {
        $start = CarbonImmutable::now()->subMonth()->startOfMonth();

        return [
            'period_start' => $start,
            'period_end' => $start->endOfMonth(),
            'quantity' => 150,
            'unit' => UsageUnit::Gigabytes,
            'source' => 'test-metering',
            'recorded_at' => CarbonImmutable::now(),
        ];
    }

    public function forMonth(string $month): self
    {
        $start = CarbonImmutable::parse($month.'-01')->startOfMonth();

        return $this->state(fn (): array => [
            'period_start' => $start,
            'period_end' => $start->endOfMonth(),
        ]);
    }

    public function measuring(float $quantity): self
    {
        return $this->state(fn (): array => ['quantity' => $quantity]);
    }
}
