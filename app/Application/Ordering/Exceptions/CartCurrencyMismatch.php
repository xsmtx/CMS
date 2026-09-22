<?php

declare(strict_types=1);

namespace App\Application\Ordering\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A second currency tried to get into a cart.
 *
 * Refused rather than converted, for the same reason a storefront price is
 * never converted: the customer would be charged a number nobody entered.
 */
final class CartCurrencyMismatch extends PlatformException
{
    public static function between(string $cart, string $item): self
    {
        return new self(
            __('ordering.errors.currency_mismatch'),
            ['cart_currency' => $cart, 'item_currency' => $item],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::Conflict;
    }
}
