<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\CartItem;
use Illuminate\Support\Facades\DB;

/**
 * Change how many of a line a customer wants, or take it out.
 *
 * Removing a product line takes its addons with it: an addon without the
 * plan it attaches to is not something anyone ordered.
 */
final readonly class UpdateCartItem
{
    public function setQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity < 1) {
            $this->remove($item);

            return;
        }

        $item->update(['quantity' => $quantity]);
    }

    public function remove(CartItem $item): void
    {
        DB::transaction(static function () use ($item): void {
            $item->children()->delete();
            $item->delete();
        });
    }

    public function empty(Cart $cart): void
    {
        DB::transaction(static function () use ($cart): void {
            $cart->allItems()->delete();
            $cart->update(['promotion_id' => null, 'promotion_code' => null]);
        });
    }
}
