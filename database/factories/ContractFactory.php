<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Vendors\ContractTerm;
use App\Infrastructure\Vendors\Models\Contract;
use App\Infrastructure\Vendors\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
final class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'vendor_id' => fn (): string => Vendor::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => Vendor::query()
                ->withoutGlobalScope('organization')
                ->whereKey((string) $attributes['vendor_id'])
                ->firstOrFail()
                ->organization_id,
            'title' => 'Transit, 10G',
            'term' => ContractTerm::Yearly->value,
            'currency_code' => 'EUR',
            'amount_minor' => 1_200_00,
            'starts_on' => CarbonImmutable::now()->subYear(),
            'ends_on' => CarbonImmutable::now()->addMonths(6),
            'auto_renews' => false,
        ];
    }

    /**
     * Named `endingIn`, not `expiring`: the thing being set is the end date,
     * and whether that counts as expiring depends on the notice period.
     */
    public function endingIn(int $days): self
    {
        return $this->state(fn (): array => ['ends_on' => CarbonImmutable::now()->addDays($days)]);
    }

    public function rolling(): self
    {
        // No end date, which is a real agreement rather than missing data.
        return $this->state(fn (): array => ['ends_on' => null]);
    }

    public function autoRenewing(int $noticeDays = 30): self
    {
        return $this->state(fn (): array => [
            'auto_renews' => true,
            'notice_days' => $noticeDays,
        ]);
    }
}
