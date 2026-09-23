<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Branding\Models\BrandSetting;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandSetting>
 */
final class BrandSettingFactory extends Factory
{
    protected $model = BrandSetting::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'trading_name' => 'Northwind Hosting',
            'hide_vendor_mark' => false,
        ];
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }

    /**
     * Only the one field, which is the case inheritance exists for.
     */
    public function onlyLogo(string $url = 'https://cdn.example.test/logo.svg'): self
    {
        return $this->state(fn (): array => ['trading_name' => null, 'logo_url' => $url]);
    }
}
