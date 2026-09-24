<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tax\TaxRounding;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Tax\Models\TaxSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxSetting>
 */
final class TaxSettingFactory extends Factory
{
    protected $model = TaxSetting::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->create()->id,
            'prices_include_tax' => false,
            'rounding' => TaxRounding::PerLine->value,
            'require_tax_id_for_business' => false,
        ];
    }

    public function forOrganization(string $organizationId): self
    {
        return $this->state(fn (): array => ['organization_id' => $organizationId]);
    }

    public function inclusive(): self
    {
        return $this->state(fn (): array => ['prices_include_tax' => true]);
    }

    public function rounding(TaxRounding $rounding): self
    {
        return $this->state(fn (): array => ['rounding' => $rounding->value]);
    }
}
