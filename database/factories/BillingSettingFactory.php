<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Billing\Models\BillingSetting;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingSetting>
 */
final class BillingSettingFactory extends Factory
{
    protected $model = BillingSetting::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->create()->id,
            'due_days' => 14,
            // No fee by default, because no country obliges one and a test
            // fixture that charged interest nobody asked for would be a
            // surprise in every unrelated billing test.
            'late_fee_rate_ppm' => 0,
        ];
    }

    public function forOrganization(string $organizationId): self
    {
        return $this->state(fn (): array => ['organization_id' => $organizationId]);
    }

    public function dueIn(int $days): self
    {
        return $this->state(fn (): array => ['due_days' => $days]);
    }

    /**
     * @param  string  $percent  The rate as an operator would type it, e.g. '1.5'.
     */
    public function lateFee(string $percent, ?string $label = null): self
    {
        return $this->state(fn (): array => [
            'late_fee_rate_ppm' => (int) round(((float) $percent) * 10_000),
            'late_fee_label' => $label,
        ]);
    }
}
