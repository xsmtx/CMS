<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

/**
 * What one collection actually achieved.
 *
 * Five fields instead of a count, because "it worked" is not a useful answer
 * about a poller and each of these needs a different person to do a different
 * thing:
 *
 * - `unmapped` — this platform has no name for a metric the source sent. Somebody
 *   decides whether to add one to `MetricKind`.
 * - `mismatched` — the source sent a unit from the wrong dimension. That is a bug
 *   in the adapter.
 * - `unplaced` — a reading arrived about something with no node. Either discovery
 *   has not run or the poller is watching a machine this installation does not
 *   run.
 * - `unknownTargets` — the adapter was asked about a node and could not answer.
 *   Either it has never heard of it or it has stopped watching it.
 *
 * The difference between the last two is worth the two fields: one is "we do not
 * know about their thing" and the other is "they do not know about ours".
 */
final readonly class SampleOutcome
{
    /**
     * @param  array<string, int>  $unmapped
     * @param  array<string, int>  $mismatched
     * @param  array<string, int>  $unplaced
     * @param  list<string>  $unknownTargets
     */
    public function __construct(
        public int $recorded = 0,
        public array $unmapped = [],
        public array $mismatched = [],
        public array $unplaced = [],
        public array $unknownTargets = [],
    ) {}

    public function isClean(): bool
    {
        return $this->unmapped === []
            && $this->mismatched === []
            && $this->unplaced === []
            && $this->unknownTargets === [];
    }

    public function refusedCount(): int
    {
        return array_sum($this->unmapped) + array_sum($this->mismatched) + array_sum($this->unplaced);
    }
}
