<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\BillingCycle;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionPrice>
 */
final class OptionPriceFactory extends Factory
{
    protected $model = OptionPrice::class;

    public function definition(): array
    {
        return [
            'option_id' => fn (): string => Option::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['option_id']),
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 0,
            'setup_minor' => 0,
        ];
    }

    public function forOption(Option|string $option): static
    {
        $id = $option instanceof Option ? $option->id : $option;

        return $this->state(fn (): array => [
            'option_id' => $id,
            'organization_id' => $this->organizationOf($id),
        ]);
    }

    public function cycle(BillingCycle $cycle): static
    {
        return $this->state(fn (): array => ['billing_cycle' => $cycle->value]);
    }

    public function amounts(int $recurringMinor, int $setupMinor = 0): static
    {
        return $this->state(fn (): array => [
            'recurring_minor' => $recurringMinor,
            'setup_minor' => $setupMinor,
        ]);
    }

    private function organizationOf(string $id): string
    {
        return Option::query()
            ->withoutGlobalScope('organization')
            ->whereKey($id)
            ->firstOrFail()
            ->organization_id;
    }
}
