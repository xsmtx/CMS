<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use RuntimeException;

/**
 * Something this platform will not do, and why — in two registers.
 *
 * Every refusal in this product carries **two sentences about one event**,
 * and conflating them is the mistake this class exists to stop:
 *
 * - `getMessage()` is English, names the thing, and goes in a log. It is what
 *   an engineer greps for six weeks later.
 * - `worded()` is what a person reads, in their own language, through a key.
 *
 * The rule was learned on `RackRefused` and written into CLAUDE.md, and eight
 * classes went on printing the English one at operators anyway — because each
 * had to remember to carry a key, and remembering is not a mechanism. Extending
 * this is the mechanism: a refusal cannot be constructed without a key, and
 * `RefusalWordingTest` calls every constructor in both locales.
 *
 * It stays `RuntimeException` underneath, so every existing `catch` is
 * unchanged.
 *
 * **One kind of refusal does not belong here**: one whose reasons are
 * deliberately never shown to a caller. `SessionRefused` distinguishes an
 * expired refresh token from a stolen one for the audit row alone, and every
 * one of them reaches the client as the same `unauthenticated`. Wording it
 * would be wording something nobody reads.
 */
abstract class Refused extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replacements
     */
    protected function __construct(
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
     * @return array<string, string|int>
     */
    public function replacements(): array
    {
        return $this->replacements;
    }

    /**
     * The sentence somebody reads.
     */
    public function worded(): string
    {
        return (string) __($this->key, $this->replacements);
    }
}
