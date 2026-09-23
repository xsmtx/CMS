<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * An idempotency key used for two different things.
 *
 * Either the same key arrived with a different payload — a bug in the
 * client that returning the first answer would hide — or the first request
 * under that key is still in flight, and inventing a success for the second
 * would be worse than telling it to ask again.
 */
final class IdempotencyConflictException extends PlatformException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::IdempotencyKeyConflict;
    }
}
