<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Application\Provisioning\RunServiceOperation;
use App\Application\Provisioning\TransitionService;
use App\Domain\Intelligence\Exceptions\RemediationRefused;
use App\Domain\Intelligence\ProposalState;
use App\Domain\Intelligence\RemediationAction;
use App\Domain\Provisioning\OperationOutcome;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\ReconciliationFinding;
use App\Infrastructure\Intelligence\Models\RemediationProposal;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Facades\Audit;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One place moves a proposal, and one place carries one out (§22).
 *
 * The rule `TransitionTicket` set and `RemoteHands` repeated: two places that
 * can set `applied_at` is one too many.
 *
 * **Nothing is carried out that nobody approved**, and nothing is carried out
 * about a finding that has moved since. The second check is the one worth
 * reading twice — it is `ApplyNetworkChange`'s fingerprint, for a comparison
 * rather than a device. A proposal written an hour ago about an account the
 * panel then restored would, applied, terminate a live account because it
 * looked terminated at four o'clock.
 *
 * **A refusal is recorded on the proposal rather than thrown out of it**,
 * wherever the refusal is an outcome rather than a caller's mistake. A
 * proposal left saying `approved` while the operation beside it said failed
 * would be two rows disagreeing about one event — the lesson
 * `ApplyNetworkChange` records.
 */
final readonly class Remediations
{
    public function __construct(
        private ProposeRemediation $proposals,
        private TransitionService $transitions,
        private RunServiceOperation $operations,
        private SecretRedactor $redactor,
    ) {}

    /**
     * Write the platform's own suggestion for a finding, or refresh it.
     *
     * Called by the sweep. **It never replaces a proposal somebody has
     * already decided**, and it never replaces one an operator chose
     * themselves: a sweep that overwrote a human's choice every hour would
     * be a sweep nobody could work with.
     */
    public function propose(ReconciliationFinding $finding): ?RemediationProposal
    {
        $action = $this->proposals->suggestion($finding);

        if ($action === null) {
            return null;
        }

        $open = RemediationProposal::query()
            ->where('reconciliation_finding_id', $finding->id)
            ->open()
            ->first();

        if ($open instanceof RemediationProposal) {
            // Somebody chose this one; the sweep does not know better.
            if ($open->proposed_by !== null) {
                return $open;
            }

            $open->action = $action;
            $open->finding_class = $finding->class;
            $open->save();

            return $open;
        }

        return RemediationProposal::query()->create([
            'organization_id' => $finding->organization_id,
            'reconciliation_finding_id' => $finding->id,
            'action' => $action,
            'state' => ProposalState::Proposed,
            'finding_class' => $finding->class,
            'proposed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * An operator choosing something other than what was suggested.
     *
     * Recorded as theirs — `proposed_by` is what stops the next sweep from
     * putting the suggestion back.
     */
    public function choose(
        ReconciliationFinding $finding,
        RemediationAction $action,
        StaffUser $actor,
    ): RemediationProposal {
        if (! in_array($action, $this->proposals->available($finding), strict: true)) {
            throw RemediationRefused::notAvailable((string) __($action->labelKey()));
        }

        $open = RemediationProposal::query()
            ->where('reconciliation_finding_id', $finding->id)
            ->open()
            ->first();

        if ($open instanceof RemediationProposal) {
            $open->action = $action;
            $open->proposed_by = $actor->id;
            $open->finding_class = $finding->class;
            $open->save();

            return $open;
        }

        return RemediationProposal::query()->create([
            'organization_id' => $finding->organization_id,
            'reconciliation_finding_id' => $finding->id,
            'action' => $action,
            'state' => ProposalState::Proposed,
            'finding_class' => $finding->class,
            'proposed_by' => $actor->id,
            'proposed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Yes or no, with a reason either way.
     */
    public function decide(
        RemediationProposal $proposal,
        bool $approved,
        StaffUser $actor,
        ?string $reason = null,
    ): RemediationProposal {
        if (! $proposal->state->isOpen()) {
            throw RemediationRefused::notOpen((string) __($proposal->state->labelKey()));
        }

        $proposal->state = $approved ? ProposalState::Approved : ProposalState::Rejected;
        $proposal->decided_by = $actor->id;
        $proposal->reason = $reason;
        $proposal->decided_at = CarbonImmutable::now();
        $proposal->save();

        Audit::action('intelligence.remediation.'.($approved ? 'approved' : 'rejected'))
            ->by($actor)
            ->on($proposal)
            ->forOrganization($proposal->organization_id)
            ->because($reason)
            ->withMetadata(['action' => $proposal->action->value])
            ->write();

        return $proposal;
    }

    /**
     * Carry out what somebody approved.
     *
     * The order is the feature, exactly as it is in `ApplyNetworkChange`:
     * approved, then still about what it was written about, then done.
     */
    public function apply(
        RemediationProposal $proposal,
        StaffUser $actor,
        ?string $reason = null,
    ): RemediationProposal {
        if ($proposal->state !== ProposalState::Approved) {
            // A caller's mistake rather than an outcome, so it is thrown
            // rather than written onto the row.
            throw RemediationRefused::notApproved();
        }

        if (! $proposal->matchesFinding()) {
            // An outcome: the finding moved, and the row has to say so or
            // the queue will offer this again tomorrow.
            $proposal->state = ProposalState::Stale;
            $proposal->save();

            throw RemediationRefused::stale();
        }

        /*
         * The sentence somebody typed into the confirmation. Kept on the row
         * as well as on the audit record, because the row is what the queue
         * shows and the audit log is what somebody opens a month later.
         */
        if ($reason !== null && trim($reason) !== '') {
            $proposal->reason = trim($reason);
            $proposal->save();
        }

        if (! $proposal->action->isActionable()) {
            // `Investigate` approved is somebody recording that they looked.
            return $this->finish($proposal, $actor, null);
        }

        $service = $this->serviceFor($proposal);

        try {
            $result = $this->carryOut($proposal->action, $service, $actor);
        } catch (Throwable $exception) {
            return $this->fail($proposal, $actor, $exception->getMessage());
        }

        /*
         * A provider that refused is a failure of the remediation, not of
         * the request. `RunServiceOperation` never lets an adapter's
         * exception escape as a 500 — it returns a failed result instead —
         * so a caller that only caught exceptions would record every refused
         * suspension as applied.
         */
        return $result['ok']
            ? $this->finish($proposal, $actor, $result['message'])
            : $this->fail($proposal, $actor, (string) $result['message']);
    }

    /**
     * @return array{ok: bool, message: ?string}
     */
    private function carryOut(RemediationAction $action, Service $service, StaffUser $actor): array
    {
        return match ($action) {
            // These three write here. The provider has been treated as
            // right, which is usually what happened.
            RemediationAction::AcceptSuspension => $this->accept($service, ServiceStatus::Suspended, $actor),
            RemediationAction::AcceptActivation => $this->accept($service, ServiceStatus::Active, $actor),
            RemediationAction::AcceptTermination => $this->accept($service, ServiceStatus::Terminated, $actor),

            // And these three go out to somebody else's machine.
            RemediationAction::RestoreService => $this->remote(
                $this->operations->unsuspend($service, $actor),
            ),
            RemediationAction::SuspendService => $this->remote(
                $this->operations->suspend($service, 'Reconciliation', $actor),
            ),
            RemediationAction::RemoveService => $this->remote(
                $this->operations->terminate($service, $actor),
            ),

            RemediationAction::Investigate => ['ok' => true, 'message' => null],
        };
    }

    /**
     * @return array{ok: bool, message: ?string}
     */
    private function remote(ProvisioningResult $result): array
    {
        return [
            // `AlreadyDone` is a success, because that is what makes a retry
            // safe (ADR 0026). A remediation is exactly the case: the whole
            // finding is that the two sides disagree, and an adapter saying
            // the account is already in that state is agreement.
            'ok' => $result->outcome !== OperationOutcome::Failed,
            'message' => $result->message,
        ];
    }

    /**
     * @return array{ok: bool, message: ?string}
     */
    private function accept(Service $service, ServiceStatus $status, StaffUser $actor): array
    {
        // Already there is a success, for the same reason `already_done` is.
        if ($service->status === $status) {
            return ['ok' => true, 'message' => null];
        }

        $this->transitions->handle($service, $status, $actor, 'Reconciliation');

        return ['ok' => true, 'message' => null];
    }

    private function fail(RemediationProposal $proposal, StaffUser $actor, string $message): RemediationProposal
    {
        $proposal->state = ProposalState::Failed;
        $proposal->outcome = $this->redactor->redactString($message);
        $proposal->applied_at = CarbonImmutable::now();
        $proposal->save();

        Audit::action('intelligence.remediation.failed')
            ->by($actor)
            ->on($proposal)
            ->forOrganization($proposal->organization_id)
            ->withMetadata(['action' => $proposal->action->value])
            ->write();

        return $proposal;
    }

    private function finish(RemediationProposal $proposal, StaffUser $actor, ?string $outcome): RemediationProposal
    {
        $proposal->state = ProposalState::Applied;
        $proposal->outcome = $outcome === null ? null : $this->redactor->redactString($outcome);
        $proposal->applied_at = CarbonImmutable::now();
        $proposal->save();

        Audit::action('intelligence.remediation.applied')
            ->by($actor)
            ->on($proposal)
            ->forOrganization($proposal->organization_id)
            ->because($proposal->reason)
            ->withMetadata(['action' => $proposal->action->value])
            ->write();

        return $proposal;
    }

    private function serviceFor(RemediationProposal $proposal): Service
    {
        $finding = $proposal->finding;
        $subject = $finding?->subject;

        if (! $subject instanceof Service) {
            throw RemediationRefused::nothingToActOn();
        }

        return $subject;
    }
}
