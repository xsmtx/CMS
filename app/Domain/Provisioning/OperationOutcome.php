<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * How a provisioning call ended.
 *
 * `AlreadyDone` is the one that makes retrying safe. "This account already
 * exists" is not a failure from the platform's point of view — it is the
 * state the caller was trying to reach — and an adapter that reports it as
 * an error turns every retried job into a permanent failure.
 */
enum OperationOutcome: string
{
    case Succeeded = 'succeeded';

    /** The provider says the work was already done. */
    case AlreadyDone = 'already_done';

    case Failed = 'failed';

    public function labelKey(): string
    {
        return 'provisioning.outcomes.'.$this->value;
    }

    public function isSuccessful(): bool
    {
        return $this !== self::Failed;
    }
}
