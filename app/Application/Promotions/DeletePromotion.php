<?php

declare(strict_types=1);

namespace App\Application\Promotions;

use App\Application\Promotions\Exceptions\PromotionRedeemed;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Remove a code nobody ever used.
 *
 * A redeemed code is part of the record of what customers were charged, so
 * it is deactivated rather than deleted: the redemption rows have to keep
 * pointing at something that explains them.
 */
final readonly class DeletePromotion
{
    public function handle(Promotion $promotion, ?Model $actor = null): void
    {
        $redemptions = $promotion->redemptions()->count();

        if ($redemptions > 0) {
            throw PromotionRedeemed::times($redemptions);
        }

        Audit::action('ordering.promotion.deleted')
            ->by($actor)
            ->on($promotion)
            ->forOrganization($promotion->organization_id)
            ->write();

        $promotion->delete();
    }
}
