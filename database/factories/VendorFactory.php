<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Vendors\VendorKind;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
final class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->create()->id,
            // Unique, because the table is: two vendors of one name in one
            // organization is the row somebody meant to edit.
            'name' => $this->faker->unique()->company(),
            'kind' => VendorKind::Software->value,
        ];
    }

    public function of(VendorKind $kind): self
    {
        return $this->state(fn (): array => ['kind' => $kind->value]);
    }
}
