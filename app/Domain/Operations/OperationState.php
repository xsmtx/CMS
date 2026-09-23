<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * Where a long-running business operation has got to.
 *
 * The handoff's six states, and `ManualIntervention` is the one that earns
 * its keep. It is the honest answer for a transfer the losing registrar
 * rejected or a provisioning run whose server no longer exists: no amount
 * of retrying will fix it, and a retry loop that hides that is worse than
 * a row on a screen saying somebody has to look.
 */
enum OperationState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Retrying = 'retrying';
    case Failed = 'failed';
    case ManualIntervention = 'manual_intervention';
    case Completed = 'completed';

    public function labelKey(): string
    {
        return 'operations.states.'.$this->value;
    }

    public function isFinished(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether an operator looking at the queue should see this row.
     */
    public function needsAttention(): bool
    {
        return $this === self::Failed || $this === self::ManualIntervention;
    }

    public function canRetry(): bool
    {
        return match ($this) {
            self::Failed, self::Retrying, self::ManualIntervention => true,
            self::Pending, self::Running, self::Completed => false,
        };
    }
}
