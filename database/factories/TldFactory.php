<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\CatalogStatus;
use App\Domain\Domains\DomainAction;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tld>
 */
final class TldFactory extends Factory
{
    protected $model = Tld::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'extension' => Str::lower(Str::random(6)),
            'registrar' => 'manual',
            'min_years' => 1,
            'max_years' => 10,
            'allows_transfer' => true,
            'allows_whois_privacy' => true,
            'requires_epp_code' => true,
            'supports_idn' => false,
            'status' => CatalogStatus::Active->value,
            'position' => 0,
            'grace_days' => 30,
            'redemption_days' => 30,
        ];
    }

    public function extension(string $extension): self
    {
        return $this->state(fn (): array => ['extension' => $extension]);
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }

    /**
     * A TLD with a register and a renew price, which is the minimum an
     * operator has to enter before anything is sellable.
     */
    public function priced(int $registerMinor = 1200, string $currency = 'EUR', int $years = 1): self
    {
        return $this->afterCreating(function (Tld $tld) use ($registerMinor, $currency, $years): void {
            foreach ([DomainAction::Register, DomainAction::Renew, DomainAction::Transfer] as $action) {
                TldPriceFactory::new()->create([
                    'organization_id' => $tld->organization_id,
                    'tld_id' => $tld->id,
                    'action' => $action->value,
                    'years' => $years,
                    'currency_code' => $currency,
                    'amount_minor' => $registerMinor,
                ]);
            }
        });
    }
}
