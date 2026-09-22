<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Promotions\PromotionEngine;
use App\Domain\Promotions\PromotionRefusal;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Promotions\Models\Promotion;

/**
 * Attaches a code to a cart, or says why not.
 *
 * The code is stored on the cart rather than the discount: what a code is
 * worth depends on what is in the cart, and freezing the amount here would
 * be wrong the moment a line changes.
 */
final readonly class ApplyPromotionCode
{
    public function __construct(private PromotionEngine $engine) {}

    public function handle(Cart $cart, string $code): ?PromotionRefusal
    {
        $found = $this->engine->find($cart->organization_id, $code);
        $promotion = $found['promotion'];

        if (! $promotion instanceof Promotion) {
            return PromotionRefusal::NotFound;
        }

        if ($found['refusal'] instanceof PromotionRefusal) {
            return $found['refusal'];
        }

        $cart->update([
            'promotion_id' => $promotion->id,
            'promotion_code' => $promotion->code,
        ]);

        return null;
    }

    public function remove(Cart $cart): void
    {
        $cart->update(['promotion_id' => null, 'promotion_code' => null]);
    }
}
