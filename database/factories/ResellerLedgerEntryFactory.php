<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Resellers\ResellerLedgerKind;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resellers\Models\ResellerLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResellerLedgerEntry>
 */
final class ResellerLedgerEntryFactory extends Factory
{
    protected $model = ResellerLedgerEntry::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::factory()->reseller()->create()->id,
            'kind' => ResellerLedgerKind::Payment->value,
            'currency_code' => 'EUR',
            'amount_minor' => 10000,
            // Zero, not the amount: the running balance is `ResellerLedger`'s
            // to compute. A factory that guessed it would let a test pass
            // against a balance nothing had actually calculated.
            'balance_minor' => 0,
            'description' => null,
            'recorded_by' => null,
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    public function forReseller(Organization|string $reseller): static
    {
        $id = $reseller instanceof Organization ? $reseller->id : $reseller;

        return $this->state(fn (): array => ['organization_id' => $id]);
    }

    public function kind(ResellerLedgerKind $kind): static
    {
        return $this->state(fn (): array => ['kind' => $kind->value]);
    }

    public function amount(int $minor, string $currency = 'EUR'): static
    {
        return $this->state(fn (): array => [
            'amount_minor' => $minor,
            'currency_code' => $currency,
        ]);
    }
}
