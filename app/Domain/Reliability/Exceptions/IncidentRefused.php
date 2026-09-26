<?php

declare(strict_types=1);

namespace App\Domain\Reliability\Exceptions;

use RuntimeException;

/**
 * Something that will not be done to an incident, and why.
 *
 * One constructor per reason, like `ChangeRefused` and `GrantRefused`: "it
 * did not work" makes a resolved incident, a cross-organization alert and a
 * caller taking a shortcut look identical in a log, and they are three
 * different conversations.
 */
final class IncidentRefused extends RuntimeException
{
    public static function alreadyResolved(string $reference): self
    {
        return new self($reference.' is resolved. Open a new incident rather than reopening this one.');
    }

    /**
     * Resolving has a figure to freeze and a timestamp to write. A second way
     * to end an incident would be the one that forgot.
     */
    public static function resolveSeparately(): self
    {
        return new self('An incident is resolved through its own action, not by posting an update.');
    }

    /**
     * The impact was worked out from exactly these alerts and then frozen.
     * Changing the evidence afterwards leaves a stored figure nobody can
     * reconcile with the rows it was computed from.
     */
    public static function evidenceIsSettled(string $reference): self
    {
        return new self($reference.' is resolved and its impact is frozen. The alerts it was computed from cannot change.');
    }

    /**
     * A postmortem written during an outage is a guess, and the timeline it
     * is written from is not finished yet.
     */
    public static function notResolvedYet(string $reference): self
    {
        return new self($reference.' has not been resolved. A postmortem is written once it has ended.');
    }

    public static function differentOrganization(): self
    {
        return new self('That alert belongs to a different organization.');
    }
}
