<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

/**
 * What the comparison found, and what it could not ask.
 *
 * The counts beside the rows are the point of this class. An empty list means
 * one of three completely different things — nothing is noisy, no adapter
 * reports per service, or no machine carries enough neighbours to compare —
 * and a screen that drew the same empty state for all three would be telling
 * an operator "everything is fine" when the truth is "nothing was measured".
 */
final readonly class NeighbourReport
{
    /**
     * @param  list<NoisyRow>  $rows
     */
    public function __construct(
        public array $rows,
        /** Machines whose services reported at least one contended metric. */
        public int $nodesCompared,
        /** Machines with services but no per-service reading at all. */
        public int $nodesWithoutPerServiceMetrics,
        /** Machines reporting per service, with too few services to compare. */
        public int $nodesTooFew,
        public float $multiple,
        public int $minimumNeighbours,
    ) {}

    /**
     * Whether anything was actually measured.
     *
     * The plan (§9) says it in as many words: where only per-node metrics
     * exist the answer is "nothing can be said", in words, rather than a
     * comparison of one.
     */
    public function measuredAnything(): bool
    {
        return $this->nodesCompared > 0;
    }
}
