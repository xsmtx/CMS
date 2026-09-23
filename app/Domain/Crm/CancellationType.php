<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * When a customer wants it to stop.
 *
 * Two answers, and they are genuinely different commercially. `immediate`
 * means stop now and the remaining term is a refund question. `end_of_term`
 * means they have paid until a date and expect to keep what they bought
 * until it — which is also the default, because a customer who did not say
 * otherwise has bought until then.
 */
enum CancellationType: string
{
    case Immediate = 'immediate';
    case EndOfTerm = 'end_of_term';

    public function labelKey(): string
    {
        return 'crm.cancellations.types.'.$this->value;
    }
}
