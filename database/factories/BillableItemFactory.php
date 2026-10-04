<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Billing\Models\BillableItem;
use App\Infrastructure\Crm\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillableItem>
 */
final class BillableItemFactory extends Factory
{
    protected $model = BillableItem::class;

    public function definition(): array
    {
        return [
            'customer_id' => fn (): string => Customer::factory()->create()->id,
            /*
             * Read from the customer rather than created on its own: a
             * customer is an organization of its own in this product, and a
             * charge in a different one from the customer it is about is a row
             * the boundary would hide from one side and show on the other.
             */
            'organization_id' => fn (array $attributes): string => Customer::query()
                ->withoutGlobalScope('organization')
                ->whereKey($attributes['customer_id'])
                ->firstOrFail()
                ->organization_id,
            'description' => 'Migration, one hour',
            'quantity' => 1,
            'currency_code' => 'EUR',
            'unit_amount_minor' => 75_00,
        ];
    }

    /**
     * Not before a date. The ordinary request — "bill this with their January
     * renewal" — has one in it.
     */
    public function notBefore(string $date): self
    {
        return $this->state(fn (): array => ['charge_on' => CarbonImmutable::parse($date)]);
    }

    /**
     * A negotiated reduction, which is a one-off charge of a negative amount.
     */
    public function credit(int $minor): self
    {
        return $this->state(fn (): array => [
            'description' => 'Agreed reduction',
            'unit_amount_minor' => -$minor,
        ]);
    }
}
