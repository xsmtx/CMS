<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Infrastructure\MetricKind;
use App\Domain\Intelligence\AllocationStrategy;
use App\Domain\Intelligence\CostPeriod;
use App\Domain\Intelligence\CostScope;
use App\Infrastructure\Intelligence\Models\CostEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostEntry>
 */
final class CostEntryFactory extends Factory
{
    protected $model = CostEntry::class;

    public function definition(): array
    {
        return [
            'label' => 'Hetzner AX102',
            'vendor' => 'Hetzner',
            'scope' => CostScope::Installation,
            'currency_code' => 'EUR',
            'amount_minor' => 20000,
            'period' => CostPeriod::Monthly,
            'strategy' => AllocationStrategy::Even,
        ];
    }

    public function against(CostScope $scope, string $type, string $id): self
    {
        return $this->state(fn (): array => [
            'scope' => $scope,
            'subject_type' => $type,
            'subject_id' => $id,
        ]);
    }

    public function costing(int $minor, string $currency = 'EUR'): self
    {
        return $this->state(fn (): array => [
            'amount_minor' => $minor,
            'currency_code' => $currency,
        ]);
    }

    public function every(CostPeriod $period): self
    {
        return $this->state(fn (): array => ['period' => $period]);
    }

    public function weightedBy(MetricKind $metric): self
    {
        return $this->state(fn (): array => [
            'strategy' => AllocationStrategy::Weighted,
            'metric' => $metric,
        ]);
    }
}
