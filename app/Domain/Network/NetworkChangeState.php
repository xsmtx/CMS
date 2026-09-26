<?php

declare(strict_types=1);

namespace App\Domain\Network;

/**
 * Where a device configuration change has got to.
 *
 * §6's workflow — Request → Validate → Diff → Authorize → Approval(optional)
 * → Backup → Apply → Verify → Complete/Rollback — as the states a row can be
 * in, rather than as a list of steps somebody has to remember to follow. The
 * steps that are *arithmetic* are not states: validation and the diff happen
 * inside the request, and verification happens inside the apply, because
 * neither is a thing an operator waits in.
 *
 * Three members exist because of how they end rather than because of how they
 * look on a screen.
 *
 * `Rejected` is not `Cancelled`. One is somebody else saying no and the other
 * is the requester withdrawing; an audit trail that could not tell them apart
 * would be an audit trail nobody could use to answer why a change did not
 * happen.
 *
 * `RolledBack` is not `Failed`. A change that failed left the device in a
 * state nobody chose; one that was rolled back is back where it started, and
 * an operator arriving at three in the morning needs to know which.
 *
 * `Applying` is a real state rather than a flag, for the reason ADR 0032
 * gives: the operation row opens before the job reaches a worker, so a change
 * that never reached one is visible rather than being a request that appears
 * to have been ignored.
 */
enum NetworkChangeState: string
{
    /** Written down, with a reason and the configuration it intends. */
    case Requested = 'requested';

    /** Waiting for somebody who is not the requester. */
    case AwaitingApproval = 'awaiting_approval';

    /** Cleared to go, not yet gone. */
    case Authorized = 'authorized';

    /** The job is out. */
    case Applying = 'applying';

    /** The device took it and said so afterwards. */
    case Completed = 'completed';

    /** It did not go through, and the device is in whatever state that left. */
    case Failed = 'failed';

    /** The backup went back on. */
    case RolledBack = 'rolled_back';

    /** Somebody with the permission said no. */
    case Rejected = 'rejected';

    /** The requester withdrew it. */
    case Cancelled = 'cancelled';

    /**
     * Whether this change can still become something else.
     *
     * Used to refuse a second decision on a settled record rather than to
     * hide a button: a screen built from `can` flags and a server that checks
     * nothing is a screen anybody can post to.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Requested, self::AwaitingApproval, self::Authorized, self::Applying => true,
            self::Completed, self::Failed, self::RolledBack, self::Rejected, self::Cancelled => false,
        };
    }

    /** Whether an apply may still be started from here. */
    public function isApplicable(): bool
    {
        return $this === self::Authorized;
    }

    /**
     * Whether the requester may still take it back.
     *
     * Not once it is applying. A change that is already at the device is not
     * something a screen can un-ask for, and offering the button would be
     * this platform lying about what it can do.
     */
    public function isWithdrawable(): bool
    {
        return match ($this) {
            self::Requested, self::AwaitingApproval, self::Authorized => true,
            default => false,
        };
    }

    public function labelKey(): string
    {
        return 'network.changes.states.'.$this->value;
    }
}
