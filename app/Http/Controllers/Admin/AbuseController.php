<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Security\AbuseCases;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Security\AbuseAction;
use App\Domain\Security\AbuseKind;
use App\Domain\Security\AbuseState;
use App\Domain\Security\EvidenceKind;
use App\Domain\Security\Exceptions\AbuseRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\AbuseActionRequest;
use App\Http\Requests\Security\AbuseEvidenceRequest;
use App\Http\Requests\Security\AbuseNoteRequest;
use App\Http\Requests\Security\OpenAbuseCaseRequest;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Security\Models\AbuseActionRecord;
use App\Infrastructure\Security\Models\AbuseCase;
use App\Infrastructure\Security\Models\AbuseCaseEvent;
use App\Infrastructure\Security\Models\AbuseEvidence;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The abuse desk (§13).
 *
 * **Recording is Support's and acting is not.** A complaint lands on whoever
 * reads the mailbox and they must be able to write it down; suspending a
 * paying customer is a commercial decision with a contract behind it, and no
 * abuse signal is right every time. The same split as `credits.issue`.
 *
 * The list defaults to what is open, because that is the question the desk
 * has this screen open to answer.
 */
final class AbuseController extends Controller
{
    public function index(Request $request, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'security.abuse.view');

        $showAll = $request->boolean('all');

        $cases = AbuseCase::query()
            // `displayNameWith` rather than `with('customer')`: a customer
            // with no company name falls back through its primary contact,
            // and a partial load is the same exception by another route.
            ->with(Customer::displayNameWith('customer'))
            ->withCount('actions')
            ->unless($showAll, static fn ($query) => $query->open())
            ->latest('reported_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Security/Abuse', [
            'cases' => [
                'data' => array_map($this->row(...), array_values($cases->items())),
                'links' => $cases->linkCollection()->toArray(),
                'currentPage' => $cases->currentPage(),
                'lastPage' => $cases->lastPage(),
                'total' => $cases->total(),
            ],
            'filters' => ['all' => $showAll],
            'options' => [
                'kinds' => $this->options(AbuseKind::cases()),
                'severities' => $this->options(AlertSeverity::cases()),
            ],
            'can' => ['manage' => $actor->can('security.abuse.manage')],
        ]);
    }

    public function show(AbuseCase $case, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'security.abuse.view');

        $case->load([
            ...Customer::displayNameWith('customer'),
            'events.author',
            'evidence',
            'actions.decider',
            'actions.service',
            'service',
            'domain',
        ]);

        $canAct = $actor->can('security.abuse.act');

        return Inertia::render('Admin/Security/AbuseCase', [
            'abuseCase' => $this->row($case) + [
                'source' => $case->source,
                'externalReference' => $case->external_reference,
                'subjectType' => $case->subject_type,
                'subjectValue' => $case->subject_value,
                'domain' => $case->domain?->name,
                'service' => $case->service?->name,
                'events' => array_values($case->events
                    ->map(static fn (AbuseCaseEvent $event): array => [
                        'id' => $event->id,
                        'body' => $event->body,
                        'state' => $event->state->value,
                        'stateLabel' => (string) __($event->state->labelKey()),
                        'stateTone' => $event->state->tone(),
                        'author' => $event->author?->name,
                        'writtenAt' => $event->created_at?->toIso8601String(),
                    ])
                    ->all()),
                'evidence' => array_values($case->evidence
                    ->map(static fn (AbuseEvidence $evidence): array => [
                        'id' => $evidence->id,
                        'kind' => $evidence->kind->value,
                        'kindLabel' => (string) __($evidence->kind->labelKey()),
                        'reference' => $evidence->reference,
                        'capturedAt' => $evidence->captured_at->toIso8601String(),
                        'retainUntil' => $evidence->retain_until->toIso8601String(),
                    ])
                    ->all()),
                'actions' => array_values($case->actions
                    ->map(static fn (AbuseActionRecord $record): array => [
                        'id' => $record->id,
                        'action' => $record->action->value,
                        'actionLabel' => (string) __($record->action->labelKey()),
                        'state' => $record->state->value,
                        'stateLabel' => (string) __($record->state->labelKey()),
                        'stateTone' => $record->state->tone(),
                        'reason' => $record->reason,
                        'service' => $record->service?->name,
                        'decider' => $record->decider?->name,
                        'result' => $record->result,
                        'performedAt' => $record->performed_at?->toIso8601String(),
                    ])
                    ->all()),
            ],
            'options' => [
                'states' => $this->options(AbuseState::cases()),
                'actions' => $this->options(AbuseAction::cases()),
                'evidenceKinds' => $this->options(EvidenceKind::cases()),
                // Only the customer's own services, and only when there is a
                // customer: the action names one, and offering somebody
                // else's would be the worst possible dropdown in the product.
                'services' => $canAct ? $this->servicesFor($case) : [],
            ],
            'can' => [
                'manage' => $actor->can('security.abuse.manage'),
                'act' => $canAct,
            ],
        ]);
    }

    public function store(OpenAbuseCaseRequest $request, CurrentActor $actor, AbuseCases $cases): RedirectResponse
    {
        $this->refuseUnless($actor, 'security.abuse.manage');

        $data = $request->validated();
        $subject = ($data['subject_type'] ?? 'none') === 'none' ? null : $data['subject_type'];

        $case = $cases->open(
            organizationId: (string) app(OrganizationContext::class)->id(),
            kind: AbuseKind::from($data['kind']),
            summary: $data['summary'],
            severity: AlertSeverity::from($data['severity']),
            subjectType: $subject,
            subjectValue: $subject === null ? null : ($data['subject_value'] ?? null),
            occurredAt: isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : null,
            source: $data['source'] ?? null,
            externalReference: $data['external_reference'] ?? null,
            actor: $this->staff($actor),
        );

        return to_route('admin.security.abuse.show', $case)
            ->with('status', __('security.abuse.opened'));
    }

    public function note(AbuseNoteRequest $request, AbuseCase $case, CurrentActor $actor, AbuseCases $cases): RedirectResponse
    {
        $this->refuseUnless($actor, 'security.abuse.manage');

        $data = $request->validated();

        try {
            $cases->note($case, $data['body'], AbuseState::from($data['state']), $this->staff($actor));
        } catch (AbuseRefused $refusal) {
            return back()->withErrors(['body' => $refusal->getMessage()]);
        }

        return back()->with('status', __('security.abuse.noted'));
    }

    public function act(AbuseActionRequest $request, AbuseCase $case, CurrentActor $actor, AbuseCases $cases): RedirectResponse
    {
        $this->refuseUnless($actor, 'security.abuse.act');

        $data = $request->validated();

        $service = isset($data['service'])
            ? Service::query()->whereKey($data['service'])->first()
            : null;

        try {
            $cases->act(
                $case,
                AbuseAction::from($data['action']),
                $data['reason'],
                $service,
                $this->staff($actor),
            );
        } catch (AbuseRefused $refusal) {
            return back()->withErrors(['action' => $refusal->getMessage()]);
        }

        return back()->with('status', __('security.abuse.acted'));
    }

    public function keep(AbuseEvidenceRequest $request, AbuseCase $case, CurrentActor $actor, AbuseCases $cases): RedirectResponse
    {
        $this->refuseUnless($actor, 'security.abuse.manage');

        $data = $request->validated();

        $cases->keep(
            $case,
            EvidenceKind::from($data['kind']),
            $data['reference'],
            $this->staff($actor),
        );

        return back()->with('status', __('security.abuse.evidence_kept'));
    }

    /**
     * The customer's own services, for the one action that needs one.
     *
     * @return list<array{value: string, label: string}>
     */
    private function servicesFor(AbuseCase $case): array
    {
        if ($case->customer_id === null) {
            return [];
        }

        return array_values(Service::query()
            ->where('customer_id', $case->customer_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Service $service): array => [
                'value' => $service->id,
                'label' => $service->name,
            ])
            ->all());
    }

    /**
     * @param  list<AbuseKind|AbuseState|AbuseAction|EvidenceKind|AlertSeverity>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_values(array_map(
            static fn (AbuseKind|AbuseState|AbuseAction|EvidenceKind|AlertSeverity $case): array => [
                'value' => $case->value,
                'label' => (string) __($case->labelKey()),
            ],
            $cases,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AbuseCase $case): array
    {
        return [
            'id' => $case->id,
            'reference' => $case->reference,
            'summary' => $case->summary,
            'kind' => $case->kind->value,
            'kindLabel' => (string) __($case->kind->labelKey()),
            // The value, the word and the tone: a status crossing to the
            // browser is never one of the three.
            'state' => $case->state->value,
            'stateLabel' => (string) __($case->state->labelKey()),
            'stateTone' => $case->state->tone(),
            'severity' => $case->severity->value,
            'severityLabel' => (string) __($case->severity->labelKey()),
            'severityTone' => $case->severity->tone(),
            'customer' => $case->customer?->displayName(),
            'customerId' => $case->customer_id,
            'occurredAt' => $case->occurred_at->toIso8601String(),
            'reportedAt' => $case->reported_at->toIso8601String(),
            'closedAt' => $case->closed_at?->toIso8601String(),
            'actionCount' => $case->actions_count ?? $case->actions()->count(),
        ];
    }

    private function staff(CurrentActor $actor): ?StaffUser
    {
        $staff = $actor->model();

        return $staff instanceof StaffUser ? $staff : null;
    }

    private function refuseUnless(CurrentActor $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }
    }
}
