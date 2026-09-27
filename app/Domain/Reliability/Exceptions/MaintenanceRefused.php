<?php

declare(strict_types=1);

namespace App\Domain\Reliability\Exceptions;

use RuntimeException;

/**
 * A maintenance window that will not be written, and why.
 *
 * One constructor per reason, like every other refusal in this context.
 */
final class MaintenanceRefused extends RuntimeException
{
    public static function endsBeforeItStarts(): self
    {
        return new self('A maintenance window has to end after it starts.');
    }

    /**
     * Cancelling one that already ran would be rewriting what happened — and
     * the alerts it suppressed carry its id.
     */
    public static function alreadyOver(string $title): self
    {
        return new self('"'.$title.'" is over. A window that has run cannot be called off.');
    }
}
