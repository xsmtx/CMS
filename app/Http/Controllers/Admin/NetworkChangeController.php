<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Network\DecideNetworkChange;
use App\Application\Operations\WatchedDispatch;
use App\Domain\Network\Exceptions\ChangeRefused;
use App\Domain\Network\NetworkChangeState;
use App\Domain\Operations\OperationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Network\DecideNetworkChangeRequest;
use App\Http\Requests\Network\RequestNetworkChangeRequest;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Jobs\ApplyNetworkChangeJob;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The queue of changes waiting on somebody, and one change in full.
 *
 * The screen an operator opens to answer "what is waiting on me", which is why
 * the default filter is the open states rather than everything: a list of four
 * hundred completed changes is a list nobody reads to the bottom.
 *
 * **Three permissions, three sets of buttons**, and each is checked on the
 * server as well as sent as a `can` flag. A screen built from `can` flags and
 * a server that checks nothing is a screen anybody can post to.
 *
 * **The apply is dispatched, never run here.** `WatchedDispatch` opens the
 * operation row before the job reaches a worker, because an apply that never
 * got there is a firewall change an operator believes went out (ADR 0032).
 * `auth.recent` sits on the route, under `network.changes.apply` — the owner
 * rule from Phase 17: the permission check comes first so that somebody who
 * may not apply is refused rather than asked for a password and then refused.
 */
final class NetworkChangeController extends Controller
{
    public function index(Request $request, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'network.devices.view');

        $showAll = $request->boolean('all');

        $changes = NetworkChange::query()
            ->with(['device', 'requester', 'decider'])
            ->unless($showAll, static fn ($query) => $query->whereIn('state', [
                NetworkChangeState::Requested->value,
                NetworkChangeState::AwaitingApproval->value,
                NetworkChangeState::Authorized->value,
                NetworkChangeState::Applying->value,
            ]))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Network/Changes', [
            'changes' => [
                'data' => array_map($this->row(...), array_values($changes->items())),
                'links' => $changes->linkCollection()->toArray(),
                'currentPage' => $changes->currentPage(),
                'lastPage' => $changes->lastPage(),
                'total' => $changes->total(),
            ],
            'filters' => ['all' => $showAll],
            'devices' => $this->devices(),
            'can' => [
                'request' => $actor->can('network.changes.request'),
                'approve' => $actor->can('network.changes.approve'),
                'apply' => $actor->can('network.changes.apply'),
            ],
        ]);
    }

    public function show(NetworkChange $change, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'network.devices.view');

        $change->load(['device', 'requester', 'decider', 'operation']);

        $staff = $actor->model();

        return Inertia::render('Admin/Network/Change', [
            'change' => $this->row($change) + [
                'reason' => $change->reason,
                'ticket' => $change->ticket,
                'decisionNote' => $change->decision_note,
                'diff' => $change->diff,
                'result' => $change->result,
                'backedUpAt' => $change->backed_up_at?->toIso8601String(),
                'appliedAt' => $change->applied_at?->toIso8601String(),
                'operationId' => $change->operation_id,
            ],
            'can' => [
                // The rule that cannot be a permission: a permission says who
                // may approve and cannot say whose change.
                'decide' => $actor->can('network.changes.approve')
                    && $change->state === NetworkChangeState::AwaitingApproval
                    && $change->requested_by !== $staff?->getKey(),
                'apply' => $actor->can('network.changes.apply') && $change->state->isApplicable(),
                'cancel' => $change->state->isWithdrawable()
                    && ($change->requested_by === $staff?->getKey() || $actor->can('network.changes.approve')),
                'isRequester' => $change->requested_by === $staff?->getKey(),
            ],
        ]);
    }

    public function store(RequestNetworkChangeRequest $request, CurrentActor $actor): RedirectResponse
    {
        $this->refuseUnless($actor, 'network.changes.request');

        $data = $request->validated();

        $device = ResourceNode::query()->whereKey($data['device'])->firstOrFail();

        try {
            $change = $request->use()->handle(
                device: $device,
                requester: $this->staff($actor),
                summary: $data['summary'],
                reason: $data['reason'],
                intended: $data['intended'],
                ticket: $data['ticket'] ?? null,
            );
        } catch (ChangeRefused $refusal) {
            return back()->withErrors(['intended' => $refusal->getMessage()])->withInput();
        }

        return to_route('admin.network.changes.show', $change)
            ->with('status', __('network.changes.flash.requested'));
    }

    public function decide(
        DecideNetworkChangeRequest $request,
        NetworkChange $change,
        CurrentActor $actor,
        DecideNetworkChange $decisions,
    ): RedirectResponse {
        $data = $request->validated();
        $staff = $this->staff($actor);
        $note = $data['note'] ?? null;

        // Cancelling is the requester's own, so it is the one decision that is
        // not behind the approve permission.
        if ($data['decision'] !== 'cancel') {
            $this->refuseUnless($actor, 'network.changes.approve');
        }

        try {
            match ($data['decision']) {
                'approve' => $decisions->approve($change, $staff, $note),
                'reject' => $decisions->reject($change, $staff, $note),
                default => $decisions->cancel($change, $staff, $note),
            };
        } catch (ChangeRefused $refusal) {
            return back()->withErrors(['decision' => $refusal->getMessage()]);
        }

        return back()->with('status', __('network.changes.flash.decided'));
    }

    public function apply(
        Request $request,
        NetworkChange $change,
        CurrentActor $actor,
        WatchedDispatch $dispatch,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'network.changes.apply');

        if (! $change->state->isApplicable()) {
            return back()->withErrors([
                'decision' => ChangeRefused::notApplicable($change->state->value)->getMessage(),
            ]);
        }

        $note = $request->string('note')->trim()->value();

        $operation = $dispatch->handle(
            OperationType::NetworkChangeApply,
            $change,
            new ApplyNetworkChangeJob($change->id),
            $this->staff($actor),
        );

        if ($operation !== null) {
            $change->operation_id = $operation->id;
            $change->save();
        }

        /*
         * Audited here rather than in `ApplyNetworkChange`, and deliberately
         * before the job has run: this records the **decision to apply**,
         * which is a person's act, where the outcome is the operation row and
         * the change's own state. A record written after the fact would be
         * missing exactly when a device stopped answering.
         *
         * The reason the level-4 dialog required is written down rather than
         * discarded - the rule the cancellation queue taught.
         */
        Audit::action('network.change.applied')
            ->by($this->staff($actor))
            ->on($change)
            ->forOrganization($change->organization_id)
            ->because($note === '' ? null : $note)
            ->withMetadata(['device' => $change->device?->node_key])
            ->write();

        return back()->with('status', __('network.changes.flash.applying'));
    }

    /**
     * The devices a change can be asked about: what discovery has found.
     *
     * Read from the graph rather than from a devices table, because discovery
     * is what knows a device exists and a second table would be a second
     * answer that eventually disagreed.
     *
     * @return list<array{id: string, label: string, key: string}>
     */
    private function devices(): array
    {
        return array_values(ResourceNode::query()
            ->where('kind', 'network_device')
            ->whereNull('retired_at')
            ->orderBy('label')
            ->get()
            ->map(static fn (ResourceNode $node): array => [
                'id' => $node->id,
                'label' => $node->label,
                'key' => $node->node_key,
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(NetworkChange $change): array
    {
        return [
            'id' => $change->id,
            'summary' => $change->summary,
            // Two fields, always: `status` decides the tone and `statusLabel`
            // is the word. Sending one of them is either untranslated or
            // untoned, which this product has learned four times.
            'status' => $change->state->value,
            'statusLabel' => (string) __($change->state->labelKey()),
            'device' => $change->device?->label,
            'deviceKey' => $change->device?->node_key,
            'requester' => $change->requester?->name,
            'decider' => $change->decider?->name,
            'requiresApproval' => $change->requires_approval,
            'requestedAt' => $change->created_at?->toIso8601String(),
            'decidedAt' => $change->decided_at?->toIso8601String(),
        ];
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
