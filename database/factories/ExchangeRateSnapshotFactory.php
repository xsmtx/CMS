<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Infrastructure\Shared\Models\ExchangeRateSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRateSnapshot>
 */
final class ExchangeRateSnapshotFactory extends Factory
{
    protected $model = ExchangeRateSnapshot::class;

    public function definition(): array
    {
        return [
            'currency_id' => fn (): string => CurrencyRecord::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['currency_id']),
            'code' => 'EUR',
            'rate' => '1.00000000',
            'source' => 'manual',
            'captured_at' => now(),
        ];
    }

    private function organizationOf(string $id): string
    {
        return CurrencyRecord::query()
            ->withoutGlobalScope('organization')
            ->whereKey($id)
            ->firstOrFail()
            ->organization_id;
    }
}
