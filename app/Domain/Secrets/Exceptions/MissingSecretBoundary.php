<?php

declare(strict_types=1);

namespace App\Domain\Secrets\Exceptions;

use RuntimeException;

/**
 * A secret with no owner is a secret no boundary hides.
 *
 * Refusing is the only safe answer: the alternative is a row every
 * organization in the installation can read, which is the one thing this
 * table must never contain.
 */
final class MissingSecretBoundary extends RuntimeException
{
    public static function forWrite(): self
    {
        return new self('A secret cannot be written without an organization boundary.');
    }
}
