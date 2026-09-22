<?php

declare(strict_types=1);

namespace App\Application\Billing\Exceptions;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * An order that cannot produce an invoice: it was never placed, it was
 * cancelled, or it already has one.
 */
final class OrderNotInvoiceable extends PlatformException
{
    public static function status(string $status): self
    {
        return new self(__('billing.errors.order_not_invoiceable'), ['status' => $status]);
    }

    public static function alreadyInvoiced(string $number): self
    {
        return new self(__('billing.errors.already_invoiced'), ['invoice' => $number]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::Conflict;
    }
}
