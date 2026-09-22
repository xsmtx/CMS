<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Ordering\Exceptions\CartCurrencyMismatch;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Ordering\Models\Cart;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Contracts\Session\Session;

/**
 * Finds the cart this request belongs to, or starts one.
 *
 * A cart is identified by a token in the session, so a visitor keeps theirs
 * across pages without an account. When they sign in, the cart they were
 * holding is claimed rather than replaced: losing a cart at the sign-in
 * step is a reliable way to lose an order.
 */
final readonly class ResolveCart
{
    private const string SESSION_KEY = 'ordering.cart_token';

    public function __construct(
        private Session $session,
        private OrganizationContext $context,
    ) {}

    public function current(): ?Cart
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $cart = Cart::query()->where('token', $token)->first();

        if (! $cart instanceof Cart || $cart->hasExpired()) {
            return null;
        }

        return $cart;
    }

    /**
     * The cart for this request, created if there is not one yet.
     *
     * The currency is fixed on creation. An existing cart in another
     * currency is a refusal rather than a silent conversion.
     */
    public function forCurrency(string $currencyCode): Cart
    {
        $currency = strtoupper($currencyCode);
        $cart = $this->current();

        if ($cart instanceof Cart) {
            if ($cart->currency_code !== $currency) {
                // An empty cart has nothing to lose, so it simply adopts
                // the new currency.
                if ($cart->isEmpty()) {
                    $cart->update(['currency_code' => $currency]);

                    return $cart;
                }

                throw CartCurrencyMismatch::between($cart->currency_code, $currency);
            }

            return $cart;
        }

        $cart = Cart::query()->create([
            'organization_id' => (string) $this->context->id(),
            'currency_code' => $currency,
        ]);

        $this->session->put(self::SESSION_KEY, $cart->token);

        return $cart;
    }

    /**
     * Attach the cart in the session to the contact who just signed in.
     */
    public function claim(Contact $contact): ?Cart
    {
        $cart = $this->current();

        if (! $cart instanceof Cart) {
            return null;
        }

        $cart->update([
            'contact_id' => $contact->id,
            'customer_id' => $contact->customer_id,
        ]);

        return $cart;
    }

    public function forget(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }
}
