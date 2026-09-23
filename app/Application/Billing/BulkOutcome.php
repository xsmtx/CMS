<?php

declare(strict_types=1);

namespace App\Application\Billing;

/**
 * What a bulk action actually did.
 *
 * Three numbers rather than one, because "done" is not an answer an operator
 * can act on. They selected twenty invoices; if eighteen were issued, one was
 * already issued and one had no lines, the message has to say so — and the
 * one that failed has to be findable, which is why its reason is kept.
 *
 * The same shape every sweep in this platform reports in: one row failing
 * never stops the rest, and a run that changed nothing is still a run that
 * happened.
 */
final readonly class BulkOutcome
{
    /**
     * @param  list<string>  $failures  one sanitised sentence per row that could not be done
     */
    public function __construct(
        public int $changed = 0,
        public int $skipped = 0,
        public array $failures = [],
    ) {}

    public function failed(): int
    {
        return count($this->failures);
    }

    public function touched(): int
    {
        return $this->changed + $this->skipped + $this->failed();
    }
}
