<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * How a cost is shared out (§21).
 *
 * **Core ships no allocation**, which is the decision tax, dunning and
 * placement all made. A server costs €200 a month and carries forty
 * accounts; how that €200 lands on the forty is somebody's commercial
 * judgement, not arithmetic this platform can do on their behalf.
 *
 * Two members to begin with, and a third arrives when somebody asks for it
 * rather than before. Both are honest in a way a third often is not: one
 * makes no claim at all, and the other makes a claim the graph can support.
 *
 * **Whichever produced a figure is written on the figure.** A margin whose
 * arithmetic an operator cannot check is a margin they will believe when it
 * is wrong — the rule `CapacityForecast` already lives under, and the reason
 * `ScorePlacement` writes its numbers and slugs onto every decision.
 */
enum AllocationStrategy: string
{
    /**
     * Every service on it takes the same share.
     *
     * It makes no claim about who is using what, which is exactly why it is
     * the default: it is wrong in a way everybody understands.
     */
    case Even = 'even';

    /**
     * Shared in proportion to a metric the graph already holds.
     *
     * Disk, memory, bandwidth — whatever the operator says the machine is
     * really sold by. A service the graph has no reading for falls back to
     * an even share of what is left rather than to zero: a cost of nothing
     * for the one account nobody is measuring is the answer that makes a
     * loss disappear.
     */
    case Weighted = 'weighted';

    public function labelKey(): string
    {
        return 'intelligence.costs.strategies.'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'intelligence.costs.strategy_descriptions.'.$this->value;
    }

    /** Whether the operator has to name the metric it is weighted by. */
    public function needsMetric(): bool
    {
        return $this === self::Weighted;
    }
}
