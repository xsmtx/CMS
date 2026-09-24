<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use Carbon\CarbonImmutable;

/**
 * What one collection attempt produced, including what it failed to produce.
 *
 * `unknownTargets` is the field that justifies the class existing instead of
 * returning a list of samples. An adapter asked about ten servers that answers
 * for nine has not succeeded — the tenth is either newly added, renamed, or has
 * stopped reporting, and every one of those is something an operator needs to
 * see. A bare list would make "nine of ten" and "ten of ten" the same answer,
 * and the missing server would be invisible until somebody noticed a blank
 * column.
 *
 * It is the same instinct as the bulk endpoints in Phase 11: the answer is three
 * numbers rather than the word "done".
 */
final readonly class SampleBatch
{
    /**
     * @param  list<RawSample|MetricSample>  $samples  Either; most adapters send raw and let core name them.
     * @param  list<string>  $unknownTargets  Node keys the adapter could not answer for.
     */
    public function __construct(
        public array $samples = [],
        public array $unknownTargets = [],
        public ?CarbonImmutable $collectedAt = null,
        /**
         * One sentence about why this batch is incomplete, when it is.
         *
         * Not an exception, because a partial answer is still worth writing: a
         * poller that timed out on one of four queries should still update what
         * it did read.
         */
        public ?string $note = null,
    ) {}

    public static function empty(?string $note = null): self
    {
        return new self(note: $note);
    }

    public function isEmpty(): bool
    {
        return $this->samples === [];
    }

    public function isComplete(): bool
    {
        return $this->unknownTargets === [] && $this->note === null;
    }
}
