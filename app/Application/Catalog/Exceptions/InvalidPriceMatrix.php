<?php

declare(strict_types=1);

namespace App\Application\Catalog\Exceptions;

use App\Domain\Catalog\BillingCycle;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A price matrix that would mean two different things at once.
 *
 * Saving it would leave whichever row was written last as the price, which
 * is a coin toss over what a customer is charged.
 */
final class InvalidPriceMatrix extends PlatformException
{
    public static function duplicateCell(BillingCycle $cycle, string $currencyCode): self
    {
        return new self(
            __('catalog.errors.duplicate_price_cell', [
                'cycle' => $cycle->value,
                'currency' => $currencyCode,
            ]),
            [
                'cycle' => $cycle->value,
                'currency' => $currencyCode,
            ],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
