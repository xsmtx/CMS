<?php

declare(strict_types=1);

namespace App\Domain\Risk;

/**
 * A decision, and why.
 *
 * The reasons are what make a hold actionable: "review" with no explanation
 * leaves the operator doing the evaluator's work again by hand. They are
 * also what make the rules tunable, since a queue full of one reason says
 * which threshold is wrong.
 *
 * Reasons never reach the customer. A rejection that names the rule it
 * tripped is a tuning guide for whoever tripped it.
 */
final readonly class RiskAssessment
{
    /**
     * @param  list<RiskReason>  $reasons
     */
    public function __construct(
        public RiskDecision $decision,
        public array $reasons = [],
        public ?int $score = null,
    ) {}

    public static function allow(): self
    {
        return new self(RiskDecision::Allow);
    }

    public static function review(RiskReason ...$reasons): self
    {
        return new self(RiskDecision::Review, array_values($reasons));
    }

    public static function deny(RiskReason ...$reasons): self
    {
        return new self(RiskDecision::Deny, array_values($reasons));
    }

    /**
     * Combine two assessments: the stricter decision, with both sets of
     * reasons.
     */
    public function merge(self $other): self
    {
        $score = $this->score === null && $other->score === null
            ? null
            : ($this->score ?? 0) + ($other->score ?? 0);

        return new self(
            $this->decision->strictest($other->decision),
            [...$this->reasons, ...$other->reasons],
            $score,
        );
    }

    /**
     * @return list<array{code: string, detail: array<string, scalar|null>}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (RiskReason $reason): array => [
                'code' => $reason->code,
                'detail' => $reason->detail,
            ],
            $this->reasons,
        );
    }
}
