<?php

declare(strict_types=1);

namespace App\Domain\Security\Exceptions;

use App\Domain\Shared\Refused;

/**
 * Something that will not be done to an abuse case, and why.
 *
 * One constructor per reason, like every other refusal in this product. It
 * matters here as much as it does for credits: an audit log in which "the
 * case was already closed" and "nobody said which service" look identical is
 * a log that cannot answer the only question anybody asks it.
 */
final class AbuseRefused extends Refused
{
    public static function alreadyClosed(string $reference): self
    {
        return new self(
            $reference.' is closed. Open a new case rather than reopening this one.',
            'security.abuse_errors.already_closed',
            ['reference' => $reference],
        );
    }

    /**
     * Suspension is the one action with a seam, and it needs to know which
     * service. A case may name a customer with four of them.
     */
    public static function needsAService(): self
    {
        return new self(
            'Suspending needs a service to suspend.',
            'security.abuse_errors.needs_a_service',
        );
    }
}
