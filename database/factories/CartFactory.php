<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
final class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => $this->sellingOrganization()->id,
            'contact_id' => null,
            'customer_id' => null,
            'currency_code' => 'EUR',
            'promotion_id' => null,
            'promotion_code' => null,
        ];
    }

    public function forOrganization(Organization|string $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
        ]);
    }

    public function currency(string $code): static
    {
        return $this->state(fn (): array => ['currency_code' => strtoupper($code)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    private function sellingOrganization(): Organization
    {
        $boundary = app(OrganizationContext::class)->id();

        if ($boundary !== null) {
            return Organization::query()->withoutGlobalScope('organization')->findOrFail($boundary);
        }

        return Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->first() ?? Organization::factory()->provider()->create();
    }
}
