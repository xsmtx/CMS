<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Intelligence\ProposeRemediation;
use App\Application\Intelligence\Remediations;
use App\Domain\Intelligence\Exceptions\RemediationRefused;
use App\Domain\Intelligence\ProposalState;
use App\Domain\Intelligence\ReconciliationClass;
use App\Domain\Intelligence\RemediationAction;
use App\Domain\Provisioning\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\FindingDismissal;
use App\Infrastructure\Intelligence\Models\ReconciliationFinding;
use App\Infrastructure\Intelligence\Models\RemediationProposal;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What this platform believes, against what each provider reports (§22).
 *
 * **Worst first, and `unknown` last.** The order is the screen's whole
 * argument: a customer paying for an account that is not there comes before a
 * machine nobody is billing for, and both come before a provider that did not
 * answer — which is a fact about the connection rather than about anybody's
 * account.
 *
 * Reading the queue is Support's; deciding what to do about a finding is not.
 */
final class ReconciliationController extends Controller
{
    public function __construct(
        private readonly Remediations $remediations,
        private readonly ProposeRemediation $proposals,
    ) {}

    public function index(Request $request, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'intelligence.reconciliation.view');

        $showAll = $request->boolean('all');

        $findings = ReconciliationFinding::query()
            // `decider` with them: strict mode only reports a lazy load when
            // the query returned more than one row, so a queue with one
            // finding in it would have rendered perfectly.
            ->with(['proposals' => static fn ($query) => $query
                ->with('decider')
                ->latest('proposed_at')
                ->limit(1)])
            ->unless($showAll, static fn ($query) => $query->open())
            ->orderByRaw('field(`class`, ?, ?, ?, ?)', $this->order())
            ->orderBy('first_seen_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Intelligence/Reconciliation', [
            'actions' => array_map(
                static fn (RemediationAction $action): array => [
                    'value' => $action->value,
                    'label' => (string) __($action->labelKey()),
                    'description' => (string) __($action->descriptionKey()),
                    // The line the confirmation's wording is drawn on:
                    // "this changes our record" and "this changes the
                    // customer's account" are different sentences.
                    'remote' => $action->isRemote(),
                    'reversible' => $action->isReversible(),
                ],
                RemediationAction::cases(),
            ),
            'findings' => [
                'data' => $this->rows(array_values($findings->items())),
                'links' => $findings->linkCollection()->toArray(),
                'currentPage' => $findings->currentPage(),
                'lastPage' => $findings->lastPage(),
                'total' => $findings->total(),
            ],
            'filters' => ['all' => $showAll],
            // Whether anything has ever been compared. An empty queue on an
            // installation that has never run the sweep says something
            // completely different from an empty queue on one that has.
            'compared' => ReconciliationFinding::query()->withoutGlobalScopes()->exists()
                || $findings->total() > 0,
            'can' => [
                'remediate' => $actor->can('intelligence.reconciliation.remediate'),
            ],
        ]);
    }

    /**
     * "This one is deliberate."
     *
     * Keyed the way a finding is keyed rather than by the finding's own id,
     * because the point is to survive tonight's sweep clearing this row and
     * raising an identical one.
     */
    public function dismiss(
        Request $request,
        CurrentActor $actor,
        ReconciliationFinding $finding,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'intelligence.reconciliation.remediate');

        $data = $request->validate([
            // Required, because a dismissal nobody can judge is one the next
            // operator has to undo to find out what it was for.
            'reason' => ['required', 'string', 'max:2000'],
            'until' => ['nullable', 'date', 'after:now'],
        ]);

        $staff = $this->staff($actor);

        FindingDismissal::query()->updateOrCreate(
            [
                'organization_id' => $finding->organization_id,
                'source' => $finding->source,
                'resource' => $finding->resource,
                'subject_id' => $finding->subject_id,
                'remote_key' => $finding->remote_key,
                'field' => $finding->field,
            ],
            [
                'dismissed_by' => $staff->id,
                'reason' => $data['reason'],
                'dismissed_at' => CarbonImmutable::now(),
                'until' => isset($data['until']) ? CarbonImmutable::parse($data['until']) : null,
            ],
        );

        // The finding itself is cleared rather than deleted: it was true, and
        // the row is what says for how long.
        $finding->cleared_at = CarbonImmutable::now();
        $finding->cleared_token = $finding->id;
        $finding->save();

        Audit::action('intelligence.reconciliation.dismissed')
            ->by($staff)
            ->on($finding)
            ->forOrganization($finding->organization_id)
            ->because($data['reason'])
            ->write();

        return back()->with('status', __('intelligence.reconciliation.dismissed'));
    }

    /**
     * An operator choosing something other than what was suggested.
     *
     * It does not decide anything. Choosing and approving are two presses on
     * purpose: the action an operator picks and the moment they agree to it
     * are different decisions, and a screen that did both at once would let
     * somebody terminate an account with one click on a dropdown.
     */
    public function choose(
        Request $request,
        CurrentActor $actor,
        ReconciliationFinding $finding,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'intelligence.reconciliation.remediate');

        $data = $request->validate([
            'action' => ['required', Rule::enum(RemediationAction::class)],
        ]);

        try {
            $this->remediations->choose(
                $finding,
                RemediationAction::from($data['action']),
                $this->staff($actor),
            );
        } catch (RemediationRefused $refused) {
            return back()->withErrors([
                'action' => __($refused->key(), $refused->replacements()),
            ]);
        }

        return back()->with('status', __('intelligence.reconciliation.chosen'));
    }

    /**
     * Yes or no, with a reason either way.
     */
    public function decide(
        Request $request,
        CurrentActor $actor,
        RemediationProposal $proposal,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'intelligence.reconciliation.remediate');

        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->remediations->decide(
                $proposal,
                $data['approved'],
                $this->staff($actor),
                $data['reason'] ?? null,
            );
        } catch (RemediationRefused $refused) {
            return back()->withErrors([
                'approved' => __($refused->key(), $refused->replacements()),
            ]);
        }

        return back()->with('status', __('intelligence.reconciliation.decided'));
    }

    /**
     * Carry out what somebody approved.
     *
     * Behind `auth.recent`, with the permission above it on the route — the
     * rule Phase 17 learned on the Licence screen and four phases have
     * repeated. Applying any remediation acts on a customer's account, and
     * the screen cannot know in advance which half of the enum the operator
     * will reach for.
     */
    public function apply(
        Request $request,
        CurrentActor $actor,
        RemediationProposal $proposal,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'intelligence.reconciliation.remediate');

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $applied = $this->remediations->apply(
                $proposal,
                $this->staff($actor),
                // The dialog asks for one at the two higher levels. An
                // endpoint that discarded it would be a sentence nobody
                // reads.
                $data['reason'] ?? null,
            );
        } catch (RemediationRefused $refused) {
            return back()->withErrors([
                'proposal' => __($refused->key(), $refused->replacements()),
            ]);
        }

        return back()->with('status', __(
            $applied->state === ProposalState::Failed
                ? 'intelligence.reconciliation.apply_failed'
                : 'intelligence.reconciliation.applied',
        ));
    }

    public function undismiss(
        CurrentActor $actor,
        ReconciliationFinding $finding,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'intelligence.reconciliation.remediate');

        $staff = $this->staff($actor);

        FindingDismissal::query()
            ->where('organization_id', $finding->organization_id)
            ->where('source', $finding->source)
            ->where('resource', $finding->resource)
            ->where('subject_id', $finding->subject_id)
            ->where('remote_key', $finding->remote_key)
            ->where('field', $finding->field)
            ->delete();

        Audit::action('intelligence.reconciliation.undismissed')
            ->by($staff)
            ->on($finding)
            ->forOrganization($finding->organization_id)
            ->write();

        return back()->with('status', __('intelligence.reconciliation.undismissed'));
    }

    /**
     * The order an operator wants, which is not the order the enum declares.
     *
     * `Missing` first because somebody is paying for nothing; `unknown` last
     * because it is not a conclusion about the account at all.
     *
     * Built from the enum rather than written out, so a sixth class cannot
     * end up sorting to whichever position MariaDB's `field()` gives an
     * unlisted value. That is zero, which is first.
     *
     * @return list<string>
     */
    private function order(): array
    {
        return [
            ReconciliationClass::Missing->value,
            ReconciliationClass::Drift->value,
            ReconciliationClass::Orphan->value,
            ReconciliationClass::Unknown->value,
        ];
    }

    /**
     * The page's rows, with every dismissal read in one query.
     *
     * One lookup per row would be fifty queries on a full page, which is the
     * shape `LazyLoadingTest` exists to catch — and it would not catch this
     * one, because a `first()` in a loop is not a lazy load.
     *
     * @param  list<ReconciliationFinding>  $findings
     * @return list<array<string, mixed>>
     */
    private function rows(array $findings): array
    {
        $dismissals = $this->dismissalsFor($findings);

        return array_map(
            fn (ReconciliationFinding $finding): array => $this->row(
                $finding,
                $dismissals[$this->key($finding)] ?? null,
            ),
            $findings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ReconciliationFinding $finding, ?FindingDismissal $dismissal): array
    {
        return [
            'id' => $finding->id,
            'subject' => $finding->subject_label,
            'resource' => $finding->resource,
            'resourceLabel' => (string) __('intelligence.resources.'.$finding->resource),
            // Two fields, always: the value for the tone and the word for
            // the screen.
            'class' => $finding->class->value,
            'classLabel' => (string) __($finding->class->labelKey()),
            'classTone' => $finding->class->tone(),
            'field' => $finding->field,
            'expected' => $this->worded($finding->expected),
            'found' => $this->worded($finding->found),
            'detail' => $finding->detail ?? [],
            'remoteKey' => $finding->remote_key,
            'firstSeenAt' => $finding->first_seen_at->toIso8601String(),
            'lastSeenAt' => $finding->last_seen_at->toIso8601String(),
            'clearedAt' => $finding->cleared_at?->toIso8601String(),
            'available' => array_map(
                static fn (RemediationAction $action): string => $action->value,
                $this->proposals->available($finding),
            ),
            'proposal' => $this->proposal($finding),
            'dismissal' => $dismissal === null ? null : [
                'reason' => $dismissal->reason,
                'until' => $dismissal->until?->toIso8601String(),
                'by' => $dismissal->staff?->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function proposal(ReconciliationFinding $finding): ?array
    {
        $proposal = $finding->proposals->first();

        if (! $proposal instanceof RemediationProposal) {
            return null;
        }

        return [
            'id' => $proposal->id,
            'action' => $proposal->action->value,
            'actionLabel' => (string) __($proposal->action->labelKey()),
            // Two fields, always.
            'state' => $proposal->state->value,
            'stateLabel' => (string) __($proposal->state->labelKey()),
            'stateTone' => $proposal->state->tone(),
            'remote' => $proposal->action->isRemote(),
            'reversible' => $proposal->action->isReversible(),
            'actionable' => $proposal->action->isActionable(),
            // Whether an operator chose it, which is what the sweep will not
            // overwrite.
            'chosen' => $proposal->proposed_by !== null,
            'reason' => $proposal->reason,
            'outcome' => $proposal->outcome,
            'decidedBy' => $proposal->decider?->name,
            'decidedAt' => $proposal->decided_at?->toIso8601String(),
            'appliedAt' => $proposal->applied_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<ReconciliationFinding>  $findings
     * @return array<string, FindingDismissal>
     */
    private function dismissalsFor(array $findings): array
    {
        if ($findings === []) {
            return [];
        }

        $keyed = [];

        foreach (
            FindingDismissal::query()
                ->whereIn('source', array_unique(array_map(
                    static fn (ReconciliationFinding $finding): string => $finding->source,
                    $findings,
                )))
                ->with('staff')
                ->get() as $dismissal
        ) {
            $keyed[implode('|', [
                $dismissal->source,
                $dismissal->resource,
                $dismissal->subject_id ?? '',
                $dismissal->remote_key ?? '',
                $dismissal->field ?? '',
            ])] = $dismissal;
        }

        return $keyed;
    }

    /**
     * A stored value, in the reader's own language where it is one of ours.
     *
     * The row holds raw values rather than sentences, because a word stored
     * in the language of whichever scheduler run wrote it is a word the next
     * operator cannot read. **What is not one of ours is returned
     * untouched**, which is right rather than lazy: a provider's own message
     * is evidence, and translating it would be this platform putting words
     * into somebody else's mouth.
     */
    private function worded(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $status = ServiceStatus::tryFrom($value);

        return $status instanceof ServiceStatus ? (string) __($status->labelKey()) : $value;
    }

    private function key(ReconciliationFinding $finding): string
    {
        return implode('|', [
            $finding->source,
            $finding->resource,
            $finding->subject_id ?? '',
            $finding->remote_key ?? '',
            $finding->field ?? '',
        ]);
    }

    private function staff(CurrentActor $actor): StaffUser
    {
        $staff = $actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        return $staff;
    }

    private function refuseUnless(CurrentActor $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }
    }
}
