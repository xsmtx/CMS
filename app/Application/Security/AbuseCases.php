<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Application\Provisioning\TransitionService;
use App\Application\Shared\AllocateNumber;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Security\AbuseAction;
use App\Domain\Security\AbuseActionState;
use App\Domain\Security\AbuseKind;
use App\Domain\Security\AbuseState;
use App\Domain\Security\EvidenceKind;
use App\Domain\Security\Exceptions\AbuseRefused;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Security\Models\AbuseActionRecord;
use App\Infrastructure\Security\Models\AbuseCase;
use App\Infrastructure\Security\Models\AbuseCaseEvent;
use App\Infrastructure\Security\Models\AbuseEvidence;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The abuse desk (§13).
 *
 * One class for a case's whole life, like `Incidents`: opening, saying
 * something, deciding to do something, and closing. Splitting them would put
 * the state machine in four places.
 *
 * **Opening attributes the report at the moment it is about.** Not now —
 * `AttributeReport` walks `ip_assignments` as it stood then, because a
 * complaint about last Tuesday belongs to Tuesday's holder and attributing it
 * to today's is how an innocent customer is suspended for somebody else's
 * spam.
 *
 * **Every guarded action is a person pressing a button.** Nothing here is
 * automatic and nothing ever should be: a shared address, a forwarded
 * newsletter and a competitor's complaint all look like the real thing, and a
 * platform that suspended on a signal would have to be right every time.
 *
 * **Three of the four actions land in `manual`**, and that is honest rather
 * than unfinished. Only suspension has a seam (`TransitionService`, which is
 * called rather than a row being written), and recording a decision this
 * platform cannot carry out — while saying so on the screen — is better than
 * an action that silently did nothing.
 */
final readonly class AbuseCases
{
    public function __construct(
        private AllocateNumber $numbers,
        private AttributeReport $attribution,
        private TransitionService $services,
    ) {}

    /**
     * @param  'ip'|'domain'|null  $subjectType
     */
    public function open(
        string $organizationId,
        AbuseKind $kind,
        string $summary,
        AlertSeverity $severity,
        ?string $subjectType = null,
        ?string $subjectValue = null,
        ?CarbonImmutable $occurredAt = null,
        ?string $source = null,
        ?string $externalReference = null,
        ?StaffUser $actor = null,
    ): AbuseCase {
        $now = CarbonImmutable::now();
        $occurred = $occurredAt ?? $now;

        return DB::transaction(function () use (
            $organizationId, $kind, $summary, $severity, $subjectType,
            $subjectValue, $occurred, $now, $source, $externalReference, $actor
        ): AbuseCase {
            $whose = $this->attribution->handle($organizationId, $subjectType, $subjectValue, $occurred);

            $case = AbuseCase::query()->create([
                'organization_id' => $organizationId,
                // The seller's, like every other document number (ADR 0025).
                'reference' => $this->numbers->handle($organizationId, 'abuse', 'ABU-'),
                'kind' => $kind,
                'state' => AbuseState::Open,
                'severity' => $severity,
                'source' => $source,
                'external_reference' => $externalReference,
                'summary' => $summary,
                'subject_type' => $subjectType,
                'subject_value' => $subjectValue,
                'customer_id' => $whose->customerId,
                'service_id' => $whose->serviceId,
                'domain_id' => $whose->domainId,
                'occurred_at' => $occurred,
                'reported_at' => $now,
                'opened_by' => $actor?->id,
            ]);

            $this->write($case, $summary, AbuseState::Open, $actor);

            Audit::action('security.abuse.opened')
                ->by($actor)
                ->on($case)
                ->forOrganization($organizationId)
                ->because($summary)
                ->withMetadata([
                    'kind' => $kind->value,
                    'subject' => $subjectValue,
                    // Whether the chain found anybody is the fact worth
                    // auditing: a desk that suspended the wrong customer will
                    // want to know what this platform believed at the time.
                    'attributed' => $whose->isAttributed(),
                ])
                ->write();

            return $case;
        });
    }

    /**
     * Say something, and move the state if it has moved.
     *
     * One act rather than two, the rule an incident's timeline states: moving
     * a case to `waiting_customer` without recording what the customer was
     * told is a case whose next reader cannot answer "did we warn them".
     */
    public function note(
        AbuseCase $case,
        string $body,
        AbuseState $state,
        ?StaffUser $actor = null,
    ): AbuseCaseEvent {
        if (! $case->state->isOpen()) {
            throw AbuseRefused::alreadyClosed($case->reference);
        }

        return DB::transaction(function () use ($case, $body, $state, $actor): AbuseCaseEvent {
            $event = $this->write($case, $body, $state, $actor);

            $case->state = $state;

            if (! $state->isOpen()) {
                $case->closed_at = CarbonImmutable::now();
                $case->closed_by = $actor?->id;
            }

            $case->save();

            Audit::action('security.abuse.updated')
                ->by($actor)
                ->on($case)
                ->forOrganization($case->organization_id)
                ->because($body)
                ->withMetadata(['state' => $state->value])
                ->write();

            return $event;
        });
    }

    /**
     * Decide to do something to the account, and do it where there is a seam.
     *
     * The reason is required and is **sent**: it lands on the service
     * transition beside the operator's name, which is the rule the
     * cancellation queue taught — a reason a screen collects and an endpoint
     * discards is a sentence nobody reads.
     */
    public function act(
        AbuseCase $case,
        AbuseAction $action,
        string $reason,
        ?Service $service = null,
        ?StaffUser $actor = null,
    ): AbuseActionRecord {
        if (! $case->state->isOpen()) {
            throw AbuseRefused::alreadyClosed($case->reference);
        }

        if ($action === AbuseAction::SuspendService && ! $service instanceof Service) {
            throw AbuseRefused::needsAService();
        }

        $record = AbuseActionRecord::query()->create([
            'organization_id' => $case->organization_id,
            'abuse_case_id' => $case->id,
            'action' => $action,
            // Recorded before it is attempted, which is ADR 0032's shape: an
            // action that never reached its seam is the failure nobody sees.
            'state' => AbuseActionState::Pending,
            'reason' => $reason,
            'decided_by' => $actor?->id,
            'service_id' => $service?->id,
        ]);

        Audit::action('security.abuse.action_decided')
            ->by($actor)
            ->on($case)
            ->forOrganization($case->organization_id)
            ->because($reason)
            ->withMetadata(['action' => $action->value, 'service' => $service?->id])
            ->write();

        $this->carryOut($record, $action, $service, $actor);

        return $record->fresh() ?? $record;
    }

    /**
     * Keep a reference to something, with a deadline for forgetting it.
     *
     * The deadline is set once, here, from the installation's retention
     * setting — never recomputed at read time. A policy somebody shortened in
     * March must not retroactively delete what was captured in January under
     * the terms that applied then.
     */
    public function keep(
        AbuseCase $case,
        EvidenceKind $kind,
        string $reference,
        ?StaffUser $actor = null,
        ?int $retentionDays = null,
    ): AbuseEvidence {
        $days = max(1, $retentionDays ?? (int) config('platform.abuse.retention_days', 180));
        $now = CarbonImmutable::now();

        $evidence = AbuseEvidence::query()->create([
            'organization_id' => $case->organization_id,
            'abuse_case_id' => $case->id,
            'kind' => $kind,
            'reference' => $reference,
            'captured_by' => $actor?->id,
            'captured_at' => $now,
            'retain_until' => $now->addDays($days),
        ]);

        // The reference itself is deliberately **not** in the audit metadata:
        // it is the third party's data this row exists to eventually forget,
        // and an audit log is the one table nothing deletes from.
        Audit::action('security.abuse.evidence_kept')
            ->by($actor)
            ->on($case)
            ->forOrganization($case->organization_id)
            ->withMetadata(['kind' => $kind->value, 'retain_days' => $days])
            ->write();

        return $evidence;
    }

    /**
     * Do it, or say plainly that a person still has to.
     */
    private function carryOut(
        AbuseActionRecord $record,
        AbuseAction $action,
        ?Service $service,
        ?StaffUser $actor,
    ): void {
        if (! $action->isAutomatic()) {
            $record->state = AbuseActionState::Manual;
            $record->save();

            return;
        }

        try {
            // The one place that suspends a service. Writing `suspended` on a
            // row here would be the second, and two places that can suspend
            // is one too many.
            $this->services->handle(
                $service instanceof Service ? $service : throw AbuseRefused::needsAService(),
                ServiceStatus::Suspended,
                $actor,
                $record->reason,
            );

            $record->state = AbuseActionState::Done;
            $record->performed_at = CarbonImmutable::now();
        } catch (Throwable $exception) {
            // The failure belongs on the record rather than thrown out of it:
            // a case saying `pending` beside an operation that failed is two
            // rows disagreeing about one event.
            $record->state = AbuseActionState::Failed;
            $record->result = $exception->getMessage();
        }

        $record->save();
    }

    private function write(
        AbuseCase $case,
        string $body,
        AbuseState $state,
        ?StaffUser $actor,
    ): AbuseCaseEvent {
        return AbuseCaseEvent::query()->create([
            'organization_id' => $case->organization_id,
            'abuse_case_id' => $case->id,
            'written_by' => $actor?->id,
            'state' => $state,
            'body' => $body,
        ]);
    }
}
