<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Shared\Money;

/**
 * One service's share of one cost, in one month (§21).
 *
 * **It carries how it was worked out, not just what it came to.** A margin
 * whose arithmetic an operator cannot check is a margin they will believe
 * when it is wrong — the rule `CapacityForecast` lives under and the reason
 * `ScorePlacement` writes its numbers and slugs onto every decision it
 * makes.
 *
 * `basis` is numbers and slugs rather than a sentence: a phrase stored or
 * built in the language of whichever process computed it is a phrase the
 * next operator cannot read. The screen words it.
 */
final readonly class CostShare
{
    public function __construct(
        public string $costEntryId,
        public string $label,
        public Money $amount,
        public AllocationStrategy $strategy,
        /** How many services shared it. */
        public int $across,
        /** The metric the weights came from, where there was one. */
        public ?string $metric = null,
        /** This service's weight, and the total, for a weighted share. */
        public ?float $weight = null,
        public ?float $weightTotal = null,
        /**
         * The weight was the average of the services that did report.
         *
         * Never zero: a cost of nothing for the one account nobody is
         * measuring is the answer that makes a loss disappear. `ScorePlacement`
         * settled this once already.
         */
        public bool $assumed = false,
    ) {}
}
