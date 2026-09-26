<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * One factor's answer about one node.
 *
 * `score` is always "how much this node is preferred on this count", between
 * zero and one, so ten factors measured in percentages, accounts, bytes per
 * second and days can be added up at all.
 *
 * `measure` is the thing that was actually read — 0.21 of a CPU, 11 accounts
 * per unit of weight, 34 days until a disk fills — and it exists because the
 * score alone is unreadable. An operator asking why a service landed here
 * wants the number, not the normalisation of the number.
 *
 * `assumed` means nothing reported this and the score is what the other
 * candidates averaged. It exists because the alternative is worse in both
 * directions: score an unreported factor as zero and every service goes to the
 * node the exporter forgot, leave it out of the total and a node that reports
 * nothing is judged only on how empty it is — which is the same node, winning
 * for the opposite reason. Assuming a node is as loaded as its peers is the one
 * answer that neither rewards nor punishes being invisible, and the screen says
 * which numbers were assumed.
 */
final readonly class PlacementComponent
{
    public function __construct(
        public PlacementFactor $factor,
        /** 0 is the worst candidate on this count, 1 the best. */
        public float $score,
        /** What was read, in the factor's own units. Null when there is nothing to quote. */
        public ?float $measure = null,
        /** True when this is the other candidates' average rather than a reading. */
        public bool $assumed = false,
    ) {}

    public function weight(): int
    {
        return $this->factor->weight();
    }

    public function weighted(): float
    {
        return $this->score * $this->factor->weight();
    }
}
