<?php

declare(strict_types=1);

namespace App\Application\Promotions;

use App\Domain\Promotions\PromotionScope;
use App\Domain\Promotions\PromotionType;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a discount code.
 *
 * The columns a type does not use are cleared rather than left behind: a
 * percentage promotion carrying a stale fixed amount is a row that says two
 * things, and one of them will eventually be read.
 */
final readonly class SavePromotion
{
    private const array AUDITED = [
        'code', 'name', 'type', 'amount_minor', 'currency_code', 'percentage',
        'scope', 'application', 'starts_at', 'ends_at', 'usage_limit',
        'per_customer_limit', 'minimum_subtotal_minor', 'new_customers_only',
        'stackable', 'is_active',
    ];

    public function handle(
        string $organizationId,
        PromotionAttributes $attributes,
        ?Promotion $promotion = null,
        ?Model $actor = null,
    ): Promotion {
        $creating = $promotion === null;
        $before = $creating ? [] : $promotion->only(self::AUDITED);

        $fixed = $attributes->type === PromotionType::Fixed;

        $values = [
            'code' => $attributes->code,
            'name' => $attributes->name,
            'description' => $attributes->description,
            'type' => $attributes->type->value,
            'amount_minor' => $fixed ? $attributes->amountMinor : null,
            'currency_code' => $fixed ? $attributes->currencyCode : $this->minimumCurrency($attributes),
            'percentage' => $fixed ? null : $attributes->percentage,
            'scope' => $attributes->scope->value,
            'application' => $attributes->application->value,
            'billing_cycles' => $attributes->billingCycles,
            'starts_at' => $attributes->startsAt,
            'ends_at' => $attributes->endsAt,
            'usage_limit' => $attributes->usageLimit,
            'per_customer_limit' => $attributes->perCustomerLimit,
            'minimum_subtotal_minor' => $attributes->minimumSubtotalMinor,
            'new_customers_only' => $attributes->newCustomersOnly,
            'stackable' => $attributes->stackable,
            'is_active' => $attributes->isActive,
        ];

        $saved = DB::transaction(function () use ($organizationId, $attributes, $promotion, $values): Promotion {
            if ($promotion === null) {
                $promotion = Promotion::query()->create([...$values, 'organization_id' => $organizationId]);
            } else {
                $promotion->update($values);
            }

            if ($attributes->scope === PromotionScope::Products) {
                $promotion->products()->sync($attributes->productIds);
            } else {
                $promotion->products()->detach();
            }

            return $promotion;
        });

        $audit = Audit::action($creating ? 'ordering.promotion.created' : 'ordering.promotion.updated')
            ->by($actor)
            ->on($saved)
            ->forOrganization($saved->organization_id);

        if (! $creating) {
            $audit->changed($before, $saved->only(self::AUDITED));
        }

        $audit->write();

        return $saved;
    }

    /**
     * A percentage code has no currency of its own, but a minimum order
     * value is an amount of money and needs one.
     */
    private function minimumCurrency(PromotionAttributes $attributes): ?string
    {
        return $attributes->minimumSubtotalMinor === null ? null : $attributes->currencyCode;
    }
}
