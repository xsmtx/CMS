<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Domains\DomainAction;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Domains\Models\TldPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TldPrice>
 */
final class TldPriceFactory extends Factory
{
    protected $model = TldPrice::class;

    public function definition(): array
    {
        return [
            'tld_id' => fn (): string => Tld::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Tld::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['tld_id'])
                ->firstOrFail()
                ->organization_id,
            'action' => DomainAction::Register->value,
            'years' => 1,
            'currency_code' => 'EUR',
            'amount_minor' => 1200,
            'cost_minor' => 900,
        ];
    }

    public function forTld(Tld $tld): self
    {
        return $this->state(fn (): array => [
            'tld_id' => $tld->id,
            'organization_id' => $tld->organization_id,
        ]);
    }
}
