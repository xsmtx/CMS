<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tax\TaxAppliesTo;
use App\Domain\Tax\TaxCustomerKind;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Tax\Models\TaxRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRule>
 */
final class TaxRuleFactory extends Factory
{
    protected $model = TaxRule::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->create()->id,
            'name' => 'VAT',
            'country_code' => 'GB',
            'rate_ppm' => 200_000,
            'level' => 1,
            'compound' => false,
            'applies_to' => TaxAppliesTo::All->value,
            'customer_kind' => TaxCustomerKind::All->value,
            'exempts_validated_business' => false,
            'priority' => 0,
            'is_active' => true,
        ];
    }

    public function forOrganization(string $organizationId): self
    {
        return $this->state(fn (): array => ['organization_id' => $organizationId]);
    }

    /** Parts per million, so 200000 is 20% and 99750 is 9.975%. */
    public function rate(string $name, int $partsPerMillion): self
    {
        return $this->state(fn (): array => ['name' => $name, 'rate_ppm' => $partsPerMillion]);
    }

    public function in(?string $country, ?string $region = null, ?string $postcode = null): self
    {
        return $this->state(fn (): array => [
            'country_code' => $country,
            'region_code' => $region,
            'postcode_pattern' => $postcode,
        ]);
    }

    public function atLevel(int $level, bool $compound = false): self
    {
        return $this->state(fn (): array => ['level' => $level, 'compound' => $compound]);
    }

    public function forKind(TaxCustomerKind $kind): self
    {
        return $this->state(fn (): array => ['customer_kind' => $kind->value]);
    }

    public function on(TaxAppliesTo $appliesTo): self
    {
        return $this->state(fn (): array => ['applies_to' => $appliesTo->value]);
    }

    public function exemptingBusinesses(?string $note = null): self
    {
        return $this->state(fn (): array => [
            'exempts_validated_business' => true,
            'exemption_note' => $note,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
