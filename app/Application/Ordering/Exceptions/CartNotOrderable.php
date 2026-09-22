<?php

declare(strict_types=1);

namespace App\Application\Ordering\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * The cart cannot become an order: it is empty, it has expired, or the
 * total moved while the customer was reading it.
 */
final class CartNotOrderable extends PlatformException
{
    public static function empty(): self
    {
        return new self(__('ordering.errors.cart_empty'));
    }

    public static function expired(): self
    {
        return new self(__('ordering.errors.cart_expired'));
    }

    /**
     * A price that changed underneath a customer is a conversation, not a
     * silent charge.
     */
    public static function totalChanged(string $expected, string $actual): self
    {
        return new self(
            __('ordering.errors.total_changed'),
            ['expected' => $expected, 'actual' => $actual],
        );
    }

    public static function termsRequired(): self
    {
        return new self(__('ordering.errors.terms_required'));
    }

    public static function customerCannotOrder(): self
    {
        return new self(__('ordering.errors.customer_cannot_order'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
