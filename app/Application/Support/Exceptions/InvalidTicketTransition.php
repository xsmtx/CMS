<?php

declare(strict_types=1);

namespace App\Application\Support\Exceptions;

use App\Domain\Support\TicketStatus;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class InvalidTicketTransition extends PlatformException
{
    public static function between(TicketStatus $from, TicketStatus $to): self
    {
        return new self(
            (string) __('support.errors.invalid_transition', [
                'from' => __($from->labelKey()),
                'to' => __($to->labelKey()),
            ]),
            ['from' => $from->value, 'to' => $to->value],
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidStateTransition;
    }
}
