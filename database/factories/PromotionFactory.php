<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Organizations\OrganizationType;
use App\Domain\Promotions\PromotionApplication;
use App\Domain\Promotions\PromotionScope;
use App\Domain\Promotions\PromotionType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
final class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => $this->sellingOrganization()->id,
            'code' => strtoupper(Str::random(8)),
            'name' => 'Launch offer',
            'description' => null,
            'type' => PromotionType::Percentage->value,
            'amount_minor' => null,
            'currency_code' => null,
            'percentage' => '10.00',
            'scope' => PromotionScope::Order->value,
            'application' => PromotionApplication::FirstPayment->value,
            'billing_cycles' => null,
            'starts_at' => null,
            'ends_at' => null,
            'usage_limit' => null,
            'usage_count' => 0,
            'per_customer_limit' => null,
            'minimum_subtotal_minor' => null,
            'new_customers_only' => false,
            'stackable' => false,
            'is_active' => true,
        ];
    }

    public function forOrganization(Organization|string $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
        ]);
    }

    public function code(string $code): static
    {
        return $this->state(fn (): array => ['code' => strtoupper($code)]);
    }

    public function percentage(string $percentage): static
    {
        return $this->state(fn (): array => [
            'type' => PromotionType::Percentage->value,
            'percentage' => $percentage,
            'amount_minor' => null,
            'currency_code' => null,
        ]);
    }

    public function fixed(int $minor, string $currency = 'EUR'): static
    {
        return $this->state(fn (): array => [
            'type' => PromotionType::Fixed->value,
            'amount_minor' => $minor,
            'currency_code' => strtoupper($currency),
            'percentage' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['ends_at' => now()->subDay()]);
    }

    public function notYetStarted(): static
    {
        return $this->state(fn (): array => ['starts_at' => now()->addDay()]);
    }

    public function recurring(): static
    {
        return $this->state(fn (): array => ['application' => PromotionApplication::Recurring->value]);
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
