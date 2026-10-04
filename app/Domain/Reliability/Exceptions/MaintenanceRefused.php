<?php

declare(strict_types=1);

namespace App\Domain\Reliability\Exceptions;

use App\Domain\Shared\Refused;

/**
 * A maintenance window that will not be written, and why.
 *
 * One constructor per reason, like every other refusal in this context.
 */
final class MaintenanceRefused extends Refused
{
    public static function endsBeforeItStarts(): self
    {
        return new self(
            'A maintenance window has to end after it starts.',
            'reliability.maintenance_errors.ends_before_start',
        );
    }

    /**
     * Cancelling one that already ran would be rewriting what happened — and
     * the alerts it suppressed carry its id.
     */
    public static function alreadyOver(string $title): self
    {
        return new self(
            '"'.$title.'" is over. A window that has run cannot be called off.',
            'reliability.maintenance_errors.already_over',
            ['title' => $title],
        );
    }
}
