<?php

declare(strict_types=1);

namespace App\Application\Billing\Exceptions;

use App\Domain\Shared\Money;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

/**
 * A payment, refund or credit movement that the books will not accept.
 */
final class PaymentRefused extends PlatformException
{
    public static function exceedsBalance(Money $amount, Money $balance): self
    {
        return new self(
            __('billing.errors.payment_exceeds_balance'),
            ['amount' => $amount->toDecimalString(), 'balance' => $balance->toDecimalString()],
        );
    }

    public static function exceedsPayment(Money $amount, Money $refundable): self
    {
        return new self(
            __('billing.errors.refund_exceeds_payment'),
            ['amount' => $amount->toDecimalString(), 'refundable' => $refundable->toDecimalString()],
        );
    }

    public static function exceedsCredit(Money $amount, Money $balance): self
    {
        return new self(
            __('billing.errors.credit_exceeds_balance'),
            ['amount' => $amount->toDecimalString(), 'balance' => $balance->toDecimalString()],
        );
    }

    public static function currencyMismatch(string $expected, string $given): self
    {
        return new self(
            __('billing.errors.currency_mismatch'),
            ['expected' => $expected, 'given' => $given],
        );
    }

    public static function alreadyPaid(): self
    {
        return new self(__('billing.errors.already_paid'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PreconditionFailed;
    }
}
