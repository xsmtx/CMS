<?php

declare(strict_types=1);

namespace App\Application\Provisioning\Exceptions;

use App\Domain\Provisioning\ServiceStatus;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\PlatformException;

final class InvalidServiceTransition extends PlatformException
{
    public static function between(ServiceStatus $from, ServiceStatus $to): self
    {
        return new self(
            (string) __('provisioning.errors.invalid_transition', [
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
