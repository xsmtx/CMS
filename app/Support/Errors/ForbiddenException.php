<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * A refusal the caller is allowed to see the reason for.
 *
 * Distinct from Laravel's AuthorizationException: this is for rules the
 * product states plainly ("not while impersonating"), where hiding the
 * reason would only confuse the operator.
 */
final class ForbiddenException extends PlatformException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::Forbidden;
    }
}
