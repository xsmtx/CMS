<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * Where a remediation proposal has got to (§22).
 *
 * Shorter than `NetworkChangeState` on purpose. There is no `Applying`
 * because a remediation is one call rather than a workflow, no `RolledBack`
 * because there is no backup to put back, and no `Cancelled` distinct from
 * `Rejected` because the platform proposed it — there is no requester to
 * withdraw.
 *
 * `Stale` is the member that is not in the network workflow, and it is this
 * one's equivalent of the fingerprint check: a proposal is about a finding as
 * it was, and a finding that has changed since is a proposal nobody agreed
 * to. Approving it would apply yesterday's reading to today's account.
 */
enum ProposalState: string
{
    /** The platform suggested it. Nobody has agreed to anything. */
    case Proposed = 'proposed';

    /** Somebody with the permission said yes; it has not run yet. */
    case Approved = 'approved';

    /** It ran and the provider agreed. */
    case Applied = 'applied';

    /** It ran and did not work. The account is wherever that left it. */
    case Failed = 'failed';

    /** Somebody with the permission said no. */
    case Rejected = 'rejected';

    /**
     * The finding moved underneath it.
     *
     * Never a conclusion about the account: it says the proposal is about
     * something that is no longer true.
     */
    case Stale = 'stale';

    public function labelKey(): string
    {
        return 'intelligence.proposal_states.'.$this->value;
    }

    /**
     * A word `status.ts` knows, pinned for every enum by `VocabularyTest`.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Proposed => 'info',
            self::Approved => 'maintenance',
            self::Applied => 'healthy',
            self::Failed => 'critical',
            self::Rejected => 'neutral',
            self::Stale => 'unknown',
        };
    }

    /** Whether a decision can still be made about it. */
    public function isOpen(): bool
    {
        return $this === self::Proposed;
    }
}
