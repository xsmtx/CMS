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
 *
 * **The sentence an operator reads is `key()`, not the message.** The message
 * is English and belongs in a log; it was the only thing here for a phase,
 * and the admin screen put it straight into a form error — so a Turkish
 * operator resolving an incident twice was answered in English. That is the
 * lesson `RackRefused` cost, arriving through a door that was already open.
 */
final class IncidentRefused extends RuntimeException
{
    /**
     * @param  array<string, string>  $replacements
     */
    private function __construct(
        string $message,
        private readonly string $key,
        private readonly array $replacements = [],
    ) {
        parent::__construct($message);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, string>
     */
    public function replacements(): array
    {
        return $this->replacements;
    }

    public static function alreadyResolved(string $reference): self
    {
        return new self(
            $reference.' is resolved. Open a new incident rather than reopening this one.',
            'reliability.errors.already_resolved',
            ['reference' => $reference],
        );
    }

    /**
     * Resolving has a figure to freeze and a timestamp to write. A second way
     * to end an incident would be the one that forgot.
     */
    public static function resolveSeparately(): self
    {
        return new self(
            'An incident is resolved through its own action, not by posting an update.',
            'reliability.errors.resolve_separately',
        );
    }

    /**
     * The impact was worked out from exactly these alerts and then frozen.
     * Changing the evidence afterwards leaves a stored figure nobody can
     * reconcile with the rows it was computed from.
     */
    public static function evidenceIsSettled(string $reference): self
    {
        return new self(
            $reference.' is resolved and its impact is frozen. The alerts it was computed from cannot change.',
            'reliability.errors.evidence_settled',
            ['reference' => $reference],
        );
    }

    /**
     * A postmortem written during an outage is a guess, and the timeline it
     * is written from is not finished yet.
     */
    public static function notResolvedYet(string $reference): self
    {
        return new self(
            $reference.' has not been resolved. A postmortem is written once it has ended.',
            'reliability.errors.not_resolved_yet',
            ['reference' => $reference],
        );
    }

    public static function differentOrganization(): self
    {
        return new self(
            'That alert belongs to a different organization.',
            'reliability.errors.different_organization',
        );
    }

    /**
     * The sentence somebody reads, in their own language.
     */
    public function worded(): string
    {
        return (string) __($this->key, $this->replacements);
    }
}
