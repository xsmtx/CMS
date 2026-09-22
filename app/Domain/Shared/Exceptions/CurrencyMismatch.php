<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use DomainException;

/**
 * Arithmetic attempted between two different currencies.
 *
 * Always a programming error rather than a user one: there is no exchange
 * rate in scope at the point of the operation, and picking one silently
 * would produce a number nobody can explain afterwards.
 */
final class CurrencyMismatch extends DomainException
{
    public static function between(string $left, string $right): self
    {
        return new self(
            "Cannot operate on {$left} and {$right} together: convert explicitly with a stated rate."
        );
    }
}
