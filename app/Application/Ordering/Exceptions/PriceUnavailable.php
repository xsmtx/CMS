<?php

declare(strict_types=1);

namespace App\Application\Ordering\Exceptions;

use App\Domain\Catalog\BillingCycle;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * Something in the cart has no price for the cycle and currency asked for.
 *
 * Absence means not sold, so this is a refusal rather than a fallback: the
 * alternative is inventing a number, which is how a customer ends up
 * charged something nobody entered.
 */
final class PriceUnavailable extends PlatformException
{
    public static function for(string $name, ?BillingCycle $cycle, string $currency): self
    {
        return new self(
            __('ordering.errors.price_unavailable', ['currency' => $currency]),
            [
                'item' => $name,
                'billing_cycle' => $cycle?->value,
                'currency' => $currency,
            ],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
