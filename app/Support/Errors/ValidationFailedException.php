<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * Input this platform will not accept, raised outside a form request.
 *
 * Laravel's own `ValidationException` already maps to the envelope, and a
 * form request should still be the first choice. This exists for the checks
 * a form request cannot express — an undeclared sort field, an unknown
 * query parameter — where the honest answer is a 422 with the field named
 * rather than a silently ignored input.
 */
final class ValidationFailedException extends PlatformException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }
}
