<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * What could be done about a difference (§22).
 *
 * **Two halves, and which half a member is in decides everything about it.**
 * Bringing the record here into line with the provider is a row change this
 * platform can undo; going to the provider and changing the customer's
 * account is not, and the difference is not a matter of degree.
 *
 * - `Accept*` writes here. The provider has been treated as right, which is
 *   usually the correct reading: somebody suspended an account in the panel
 *   during an incident, and the record is what is stale.
 * - `Restore*` and `Remove*` go out. They are the ones that carry the
 *   password challenge, and `Remove` is the only one of them that cannot be
 *   undone by doing the opposite afterwards.
 *
 * **There is deliberately no `Fix` member.** An action named after its
 * outcome rather than its direction is how somebody approves a thing that
 * terminates a customer's hosting account believing they have corrected a
 * spreadsheet.
 *
 * **`Investigate` is a real member.** Most of the queue is neither: an
 * `unknown` finding has nothing to remediate and an orphan on a machine
 * nobody recognises wants a person, not a button. A proposal that said
 * "nothing can be proposed" would be a proposal, which is worse than an
 * honest word.
 */
enum RemediationAction: string
{
    /** Mark it suspended here, because the panel already has. */
    case AcceptSuspension = 'accept_suspension';

    /** Mark it active here, because the panel says it is. */
    case AcceptActivation = 'accept_activation';

    /** Mark it terminated here, because the account is gone. */
    case AcceptTermination = 'accept_termination';

    /** Ask the provider to unsuspend an account we think is paid for. */
    case RestoreService = 'restore_service';

    /** Ask the provider to suspend an account we think is not. */
    case SuspendService = 'suspend_service';

    /**
     * Ask the provider to destroy an account nobody here owns.
     *
     * The one member with no opposite. It is proposed and never
     * pre-selected, and §22's own "safe remediation" means this is the
     * unsafe one that has to be typed out.
     */
    case RemoveService = 'remove_service';

    /** Somebody has to go and look. */
    case Investigate = 'investigate';

    public function labelKey(): string
    {
        return 'intelligence.actions.'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'intelligence.action_descriptions.'.$this->value;
    }

    /**
     * Whether this reaches the provider.
     *
     * The line the password challenge is drawn on, and the line the
     * confirmation's wording changes across: "this changes our record" and
     * "this changes the customer's account" are different sentences.
     */
    public function isRemote(): bool
    {
        return match ($this) {
            self::RestoreService, self::SuspendService, self::RemoveService => true,
            self::AcceptSuspension, self::AcceptActivation,
            self::AcceptTermination, self::Investigate => false,
        };
    }

    /**
     * Whether doing the opposite afterwards would put it back.
     *
     * Only one member answers false, and it is the reason the confirmation
     * for it asks the operator to type the service's own name.
     */
    public function isReversible(): bool
    {
        return $this !== self::RemoveService;
    }

    /**
     * Whether anything is actually carried out.
     *
     * `Investigate` is an answer, not an operation: approving it records
     * that somebody looked and moves nothing.
     */
    public function isActionable(): bool
    {
        return $this !== self::Investigate;
    }
}
