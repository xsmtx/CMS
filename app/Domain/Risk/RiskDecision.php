<?php

declare(strict_types=1);

namespace App\Domain\Risk;

/**
 * What the risk layer concluded.
 *
 * Three outcomes, not two: the useful answer to most suspicious orders is
 * "a human should look at this", and a binary allow/deny forces an operator
 * to choose between losing business and taking it blind.
 */
enum RiskDecision: string
{
    case Allow = 'allow';
    case Review = 'review';
    case Deny = 'deny';

    public function labelKey(): string
    {
        return 'ordering.risk.'.$this->value;
    }

    public function holdsFulfilment(): bool
    {
        return $this !== self::Allow;
    }

    /**
     * The more cautious of two decisions.
     *
     * Several evaluators can run over one order; the strictest wins, because
     * an evaluator that says "deny" has found something the others did not
     * look for.
     */
    public function strictest(self $other): self
    {
        $order = [self::Allow->value => 0, self::Review->value => 1, self::Deny->value => 2];

        return $order[$this->value] >= $order[$other->value] ? $this : $other;
    }
}
