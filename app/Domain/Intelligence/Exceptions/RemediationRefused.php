<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Exceptions;

use RuntimeException;

/**
 * A remediation that did not go ahead (§22).
 *
 * Every reason named separately, the rule `PackageRefused` set. `stale` is
 * the one worth reading twice: it means the finding moved underneath the
 * proposal, and an operator reading "it failed" would go and look at the
 * provider instead of at the queue.
 *
 * The sentence an operator reads is `key()` rather than the message, which
 * is English and belongs in a log — the lesson `RackRefused` cost.
 */
final class RemediationRefused extends RuntimeException
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

    public static function notOpen(string $state): self
    {
        return new self(
            'A proposal that is '.$state.' cannot be decided again.',
            'intelligence.errors.not_open',
            ['state' => $state],
        );
    }

    public static function notApproved(): self
    {
        return new self(
            'Nothing is carried out until somebody approves it.',
            'intelligence.errors.not_approved',
        );
    }

    /**
     * The finding moved underneath the proposal.
     *
     * The fingerprint check's refusal, for a comparison rather than a
     * device. Applying it would put yesterday's reading onto today's
     * account.
     */
    public static function stale(): self
    {
        return new self(
            'The difference this was written about is no longer what it was.',
            'intelligence.errors.stale',
        );
    }

    public static function notAvailable(string $action): self
    {
        return new self(
            'A finding of this kind cannot be answered with '.$action.'.',
            'intelligence.errors.not_available',
            ['action' => $action],
        );
    }

    public static function nothingToActOn(): self
    {
        return new self(
            'There is no service behind this finding to act on.',
            'intelligence.errors.nothing_to_act_on',
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
