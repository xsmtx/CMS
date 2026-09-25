<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Ordering\AddToCart;
use App\Application\Ordering\AddToCartRequest;
use App\Application\Ordering\ApplyPromotionCode;
use App\Application\Ordering\PriceCart;
use App\Application\Ordering\ResolveCart;
use App\Application\Ordering\UpdateCartItem;
use App\Domain\Catalog\BillingCycle;
use App\Http\Concerns\PresentsCartTotals;
use App\Http\Requests\Ordering\AddToCartFormRequest;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\CartItem;
use App\Support\Catalog\StorefrontCurrency;
use App\Support\View\StorefrontRenderer;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The cart, as a customer sees it.
 *
 * Prices are resolved on every view rather than stored on the line, so a
 * cart left open overnight shows this morning's price. Checkout prices it
 * once more before anything is charged.
 */
final class StorefrontCartController extends Controller
{
    use PresentsCartTotals;

    public function __construct(
        private readonly ResolveCart $carts,
        private readonly PriceCart $pricer,
        private readonly StorefrontCurrency $currency,
        private readonly StorefrontRenderer $renderer,
    ) {}

    public function show(): Renderable
    {
        $cart = $this->carts->current();

        return $this->renderer->render('cart', [
            'currency' => $this->currency->current(),
            'currencies' => $this->currency->available(),
            'cart' => $cart instanceof Cart ? $this->present($this->pricer->handle($cart)) : null,
        ]);
    }

    public function store(AddToCartFormRequest $request, AddToCart $add): RedirectResponse
    {
        $currency = $this->currency->current();

        if ($currency === null) {
            return back()->withErrors(['cart' => __('ordering.errors.cart_empty')]);
        }

        $cart = $this->carts->forCurrency($currency);

        $add->handle($cart, new AddToCartRequest(
            productId: $request->string('product_id')->toString(),
            cycle: BillingCycle::from($request->string('billing_cycle')->toString()),
            quantity: (int) $request->input('quantity', 1),
            options: $request->optionChoices(),
            addonIds: $request->addonIds(),
            domain: $request->input('domain'),
        ));

        return to_route('storefront.cart')->with('status', __('ordering.cart.added'));
    }

    public function update(Request $request, string $item, UpdateCartItem $update): RedirectResponse
    {
        $update->setQuantity($this->lineFor($item), (int) $request->input('quantity', 1));

        return back()->with('status', __('ordering.cart.updated'));
    }

    public function destroy(string $item, UpdateCartItem $update): RedirectResponse
    {
        $update->remove($this->lineFor($item));

        return back()->with('status', __('ordering.cart.item_removed'));
    }

    public function applyCode(Request $request, ApplyPromotionCode $promotions): RedirectResponse
    {
        $cart = $this->carts->current();

        if (! $cart instanceof Cart) {
            return back();
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            $promotions->remove($cart);

            return back();
        }

        $refusal = $promotions->handle($cart, $code);

        if ($refusal !== null) {
            return back()->withErrors(['code' => __($refusal->messageKey(), [
                'currency' => $cart->currency_code,
                'minimum' => '',
            ])]);
        }

        return back()->with('status', __('ordering.cart.promo_applied', ['code' => strtoupper($code)]));
    }

    public function removeCode(ApplyPromotionCode $promotions): RedirectResponse
    {
        $cart = $this->carts->current();

        if ($cart instanceof Cart) {
            $promotions->remove($cart);
        }

        return back();
    }

    /**
     * A line of *this* browser's cart, or 404.
     *
     * Resolved through the cart rather than by route-model binding, and that
     * is not a style preference. Implicit binding happens inside
     * `SubstituteBindings`, which runs before the storefront narrows the
     * boundary to the seller — so a signed-in customer's own organization was
     * still in force, the line belongs to the shop, and Remove answered 404 on
     * their own basket. Reading it through the cart is also the rule this code
     * always claimed: a line is editable only by the browser holding the
     * cart's token.
     */
    private function lineFor(string $itemId): CartItem
    {
        $cart = $this->carts->current();

        $line = $cart instanceof Cart
            ? $cart->allItems()->firstWhere('id', $itemId)
            : null;

        abort_unless($line instanceof CartItem, 404);

        return $line;
    }
}
