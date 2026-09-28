<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\UsageUnit;
use App\Infrastructure\Billing\Models\UsageMeterRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageMeterRecord>
 */
final class UsageMeterRecordFactory extends Factory
{
    protected $model = UsageMeterRecord::class;

    public function definition(): array
    {
        return [
            'source' => 'test-metering',
            'meter_key' => 'bandwidth.out',
            'service_key' => 'acct'.fake()->unique()->numberBetween(1, 100000),
            'unit' => UsageUnit::Gigabytes,
            'included_quantity' => 100,
            // A third of a penny a gigabyte, which is the kind of rate that
            // makes rounding once at the line rather than per unit matter.
            'rate_minor' => 3,
            'currency_code' => 'EUR',
        ];
    }

    public function of(string $meterKey, UsageUnit $unit = UsageUnit::Gigabytes): self
    {
        return $this->state(fn (): array => ['meter_key' => $meterKey, 'unit' => $unit]);
    }

    public function priced(int $rateMinor, float $included = 0.0, string $currency = 'EUR'): self
    {
        return $this->state(fn (): array => [
            'rate_minor' => $rateMinor,
            'included_quantity' => $included,
            'currency_code' => $currency,
        ]);
    }
}
