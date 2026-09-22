<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Infrastructure\Promotions\Models\Promotion;
use App\Infrastructure\Promotions\Models\PromotionRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionRedemption>
 */
final class PromotionRedemptionFactory extends Factory
{
    protected $model = PromotionRedemption::class;

    public function definition(): array
    {
        return [
            'promotion_id' => fn (): string => Promotion::factory()->create()->id,
            'organization_id' => fn (array $attributes): string => $this->organizationOf((string) $attributes['promotion_id']),
            'order_id' => null,
            'customer_id' => null,
            'amount_minor' => 500,
            'currency_code' => 'EUR',
            'redeemed_at' => now(),
        ];
    }

    private function organizationOf(string $promotionId): string
    {
        return Promotion::query()
            ->withoutGlobalScope('organization')
            ->whereKey($promotionId)
            ->firstOrFail()
            ->organization_id;
    }
}
