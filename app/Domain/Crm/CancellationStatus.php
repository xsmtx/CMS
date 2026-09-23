<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * Where a cancellation request is.
 *
 * `withdrawn` exists because customers change their minds, and a queue that
 * could only complete a request would make an operator complete one that
 * should not happen. It is not the same as `completed`, and a report that
 * counted them together would overstate churn.
 */
enum CancellationStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Withdrawn = 'withdrawn';

    public function labelKey(): string
    {
        return 'crm.cancellations.statuses.'.$this->value;
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
