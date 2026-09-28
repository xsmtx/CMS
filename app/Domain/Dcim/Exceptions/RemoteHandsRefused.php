<?php

declare(strict_types=1);

namespace App\Domain\Dcim\Exceptions;

use RuntimeException;

/**
 * A remote-hands task that could not be moved (§11).
 *
 * Every reason named separately, the rule `PackageRefused` set.
 *
 * `needsOutcome` is the one worth reading twice: a task closed with nothing
 * written is a technician's visit nobody can read afterwards, and that
 * sentence is the reason the whole record exists.
 *
 * **The sentence an operator reads is a key, not the message.** The message
 * is English and belongs in a log; the screen renders `key()` with
 * `replacements()`, because a refusal is the one thing somebody sees at the
 * moment they are already confused — `LicenceCheck` shipped three hard-coded
 * sentences and nothing caught it for four phases.
 */
final class RemoteHandsRefused extends RuntimeException
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

    public static function cannotMove(string $from, string $to): self
    {
        return new self(
            'A task that is '.$from.' cannot become '.$to.'.',
            'dcim.remote_hands.errors.cannot_move',
            ['from' => $from, 'to' => $to],
        );
    }

    public static function needsOutcome(): self
    {
        return new self(
            'Say what happened before closing this.',
            'dcim.remote_hands.errors.needs_outcome',
        );
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
}
