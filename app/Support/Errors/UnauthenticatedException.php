<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * No usable credentials.
 *
 * Deliberately undifferentiated: a missing token, a token that was never
 * issued, one that expired and one whose holder lost portal access all
 * produce this. A caller who learns which of the four applies has learned
 * that a token exists, which is more than a caller with no token should
 * ever find out.
 */
final class UnauthenticatedException extends PlatformException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::Unauthenticated;
    }
}
