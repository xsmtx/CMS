<?php

declare(strict_types=1);

namespace App\Domain\Reliability;

/**
 * One thing a rule could be true about, at one moment.
 *
 * The evaluator's currency, and the reason it is a value object rather than a
 * row: an observation is not stored. What is stored is the **alert** that a
 * rule and an observation together produced — the reading itself already lives
 * in `resource_metrics`, in a health report, on an adapter row, and copying it
 * into a third place would be a third answer that eventually disagreed with
 * the first two.
 *
 * `key` is what the alert de-duplicates on and `label` is what an operator
 * reads. They are different on purpose: a node key is a hostname or a ULID and
 * a label is "web-1 in Frankfurt", and an alert list keyed on the label would
 * split in two the day somebody renamed a server.
 *
 * `value` is null for the subjects that are not numbers — a health check is a
 * state and an automation run is a fact. A `bad` of true with no value is the
 * ordinary shape for those, and `observed` is the sentence the screen shows.
 */
final readonly class Observation
{
    public function __construct(
        public string $key,
        public string $label,
        /** True when this observation should raise the rule. */
        public bool $bad,
        /** What the screen shows: `94%`, `failing`, `3 failed`. */
        public ?string $observed = null,
        /** The number, where there is one. */
        public ?float $value = null,
    ) {}
}
