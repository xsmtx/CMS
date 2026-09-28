<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Domain\Intelligence\ReconciliationClass;
use App\Domain\Intelligence\RemediationAction;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Intelligence\Models\ReconciliationFinding;

/**
 * What could be done about a finding, and what the platform suggests (§22).
 *
 * **The suggestion is always the action that changes the least.** Where a
 * finding could be answered either by correcting the record here or by
 * changing the customer's account there, the platform proposes the first —
 * the provider is treated as right, which is usually what happened:
 * somebody suspended an account in the panel during an incident and the
 * record is the stale half. The remote action is offered beside it and
 * never pre-selected, because "the platform suggested it" is a sentence
 * that ends up in a post-mortem.
 *
 * **An `unknown` finding has nothing to propose.** Nobody could ask, so
 * there is nothing to act on — and a proposal generated from a provider's
 * outage would be a proposal to suspend four hundred accounts. It is offered
 * `Investigate` and nothing else.
 *
 * **Termination is never suggested, only offered.** `RemoveService` has no
 * opposite and is the one action in this product that destroys somebody's
 * data on somebody else's machine. It appears in the list an operator may
 * choose from and never as the platform's own suggestion.
 */
final readonly class ProposeRemediation
{
    /**
     * The one the platform puts forward, or null where it has nothing to say.
     */
    public function suggestion(ReconciliationFinding $finding): ?RemediationAction
    {
        return match ($finding->class) {
            // Nobody could ask. There is nothing here to act on.
            ReconciliationClass::Unknown => RemediationAction::Investigate,
            ReconciliationClass::Drift => $this->driftSuggestion($finding),
            // The account is gone and we are still billing for it. The
            // record is what is wrong, and nothing at the provider can be
            // put back by this platform.
            ReconciliationClass::Missing => RemediationAction::AcceptTermination,
            // Somebody has to decide whether it is really nobody's before
            // anything destroys it.
            ReconciliationClass::Orphan => RemediationAction::Investigate,
            ReconciliationClass::Healthy => null,
        };
    }

    /**
     * Everything an operator may choose instead.
     *
     * @return list<RemediationAction>
     */
    public function available(ReconciliationFinding $finding): array
    {
        return match ($finding->class) {
            ReconciliationClass::Unknown, ReconciliationClass::Healthy => [
                RemediationAction::Investigate,
            ],
            ReconciliationClass::Missing => [
                RemediationAction::AcceptTermination,
                RemediationAction::Investigate,
            ],
            /*
             * Destroying it is offered only where there is something here to
             * act through. An orphan found by `DetectOrphans` has no row at
             * all — that is what makes it an orphan — so this platform has
             * no way to reach the machine, and a button that was offered and
             * always refused would be worse than no button.
             */
            ReconciliationClass::Orphan => $finding->subject_id === null
                ? [RemediationAction::Investigate]
                : [RemediationAction::Investigate, RemediationAction::RemoveService],
            ReconciliationClass::Drift => $this->driftActions($finding),
        };
    }

    private function driftSuggestion(ReconciliationFinding $finding): RemediationAction
    {
        return match (ServiceStatus::tryFrom((string) $finding->found)) {
            ServiceStatus::Suspended => RemediationAction::AcceptSuspension,
            ServiceStatus::Active, ServiceStatus::GracePeriod => RemediationAction::AcceptActivation,
            // A remote state this platform has no local answer for. Somebody
            // reads it rather than a rule guessing at it.
            default => RemediationAction::Investigate,
        };
    }

    /**
     * @return list<RemediationAction>
     */
    private function driftActions(ReconciliationFinding $finding): array
    {
        return match (ServiceStatus::tryFrom((string) $finding->found)) {
            ServiceStatus::Suspended => [
                RemediationAction::AcceptSuspension,
                // The other reading: they are paid up and the panel is wrong.
                RemediationAction::RestoreService,
                RemediationAction::Investigate,
            ],
            ServiceStatus::Active, ServiceStatus::GracePeriod => [
                RemediationAction::AcceptActivation,
                RemediationAction::SuspendService,
                RemediationAction::Investigate,
            ],
            default => [RemediationAction::Investigate],
        };
    }
}
