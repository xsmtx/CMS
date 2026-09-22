<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\BillingCycle;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\AddonPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddonPrice>
 */
final class AddonPriceFactory extends Factory
{
    protected $model = AddonPrice::class;

    public function definition(): array
    {
        return [
            'addon_id' => fn (): string => Addon::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['addon_id']),
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 500,
            'setup_minor' => 0,
        ];
    }

    public function forAddon(Addon|string $addon): static
    {
        $id = $addon instanceof Addon ? $addon->id : $addon;

        return $this->state(fn (): array => [
            'addon_id' => $id,
            'organization_id' => $this->organizationOf($id),
        ]);
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
        return Addon::query()
            ->withoutGlobalScope('organization')
            ->whereKey($id)
            ->firstOrFail()
            ->organization_id;
    }
}
