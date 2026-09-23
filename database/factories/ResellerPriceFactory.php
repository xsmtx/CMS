<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\BillingCycle;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resellers\Models\ResellerPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResellerPrice>
 */
final class ResellerPriceFactory extends Factory
{
    protected $model = ResellerPrice::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->reseller()->create()->id,
            'product_id' => fn (): string => Product::factory()->create()->id,
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 1999,
            'setup_minor' => 0,
        ];
    }

    public function forReseller(Organization|string $reseller): static
    {
        $id = $reseller instanceof Organization ? $reseller->id : $reseller;

        return $this->state(fn (): array => ['organization_id' => $id]);
    }

    public function forProduct(Product|string $product): static
    {
        $id = $product instanceof Product ? $product->id : $product;

        return $this->state(fn (): array => ['product_id' => $id]);
    }

    public function amounts(int $recurringMinor, int $setupMinor = 0): static
    {
        return $this->state(fn (): array => [
            'recurring_minor' => $recurringMinor,
            'setup_minor' => $setupMinor,
        ]);
    }
}
