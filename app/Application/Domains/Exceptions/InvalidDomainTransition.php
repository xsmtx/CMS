<?php

declare(strict_types=1);

namespace App\Application\Domains\Exceptions;

use App\Domain\Domains\DomainStatus;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class InvalidDomainTransition extends PlatformException
{
    public static function between(DomainStatus $from, DomainStatus $to): self
    {
        return new self(
            (string) __('domains.errors.invalid_transition', [
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
