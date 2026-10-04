<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Vendors\Models\LicencePool;
use App\Infrastructure\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicencePool>
 */
final class LicencePoolFactory extends Factory
{
    protected $model = LicencePool::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->create()->id,
            'vendor_id' => fn (): string => Vendor::factory()->create()->id,
            'name' => 'cPanel Admin, 100 accounts',
            // Null by default, which is the honest shape: a pool that names
            // no module makes no claim about which machines need it.
            'for_module' => null,
            'seats' => 10,
            'currency_code' => 'USD',
            'unit_amount_minor' => 4_50,
        ];
    }

    public function forModule(string $module): self
    {
        return $this->state(fn (): array => ['for_module' => $module]);
    }

    public function seats(int $seats): self
    {
        return $this->state(fn (): array => ['seats' => $seats]);
    }
}
