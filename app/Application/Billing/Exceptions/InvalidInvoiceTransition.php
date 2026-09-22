<?php

declare(strict_types=1);

namespace App\Application\Billing\Exceptions;

use App\Domain\Billing\InvoiceStatus;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class InvalidInvoiceTransition extends PlatformException
{
    public static function between(InvoiceStatus $from, InvoiceStatus $to): self
    {
        return new self(
            __('billing.errors.invalid_transition', ['from' => $from->value, 'to' => $to->value]),
            [
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => array_map(
                    static fn (InvoiceStatus $status): string => $status->value,
                    $from->allowedTransitions(),
                ),
            ],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }
}
