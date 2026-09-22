<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrencyRecord>
 */
final class CurrencyRecordFactory extends Factory
{
    protected $model = CurrencyRecord::class;

    public function definition(): array
    {
        return [
            // Currencies belong to whoever trades in them: the active
            // boundary when there is one, otherwise the provider.
            'organization_id' => fn (): string => $this->tradingOrganization()->id,
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => 'EUR',
            'exponent' => 2,
            'rate' => '1.00000000',
            'is_base' => false,
            'is_active' => true,
        ];
    }

    public function base(): static
    {
        return $this->state(fn (): array => ['is_base' => true, 'rate' => '1.00000000']);
    }

    public function code(string $code, string $name, string $rate = '1.00000000'): static
    {
        return $this->state(fn (): array => [
            'code' => strtoupper($code),
            'name' => $name,
            'rate' => $rate,
        ]);
    }

    public function forOrganization(Organization|string $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
        ]);
    }

    private function tradingOrganization(): Organization
    {
        $boundary = app(OrganizationContext::class)->id();

        if ($boundary !== null) {
            return Organization::query()
                ->withoutGlobalScope('organization')
                ->findOrFail($boundary);
        }

        return Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->first() ?? Organization::factory()->provider()->create();
    }
}
