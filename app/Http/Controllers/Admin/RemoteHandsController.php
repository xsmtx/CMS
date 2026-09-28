<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Dcim\RemoteHands;
use App\Domain\Dcim\Exceptions\RemoteHandsRefused;
use App\Domain\Dcim\RemoteHandsState;
use App\Http\Controllers\Controller;
use App\Infrastructure\Dcim\Models\HardwarePart;
use App\Infrastructure\Dcim\Models\Rack;
use App\Infrastructure\Dcim\Models\RemoteHandsTask;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asking somebody to go and touch a machine (§11).
 *
 * **Open first, oldest first.** A remote-hands queue is read by whoever is
 * chasing the datacenter, and the thing they need is the one that has been
 * waiting longest — not the one raised most recently.
 *
 * Asking is Support's: the person on the telephone is exactly who needs a
 * disk swapped. Closing one with the serials is the technician's half and its
 * own permission, because those two fields are the register for the next
 * warranty claim.
 */
final class RemoteHandsController extends Controller
{
    public function __construct(private readonly RemoteHands $tasks) {}

    public function index(Request $request, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'dcim.view');

        $showAll = $request->boolean('all');

        $tasks = RemoteHandsTask::query()
            ->with(['rack', 'server', 'part', 'requester'])
            ->unless($showAll, static fn ($query) => $query->open())
            // Oldest first: the thing that has been waiting longest is what
            // somebody chasing the datacenter needs.
            ->orderBy('requested_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Dcim/RemoteHands', [
            'tasks' => [
                'data' => array_map($this->row(...), array_values($tasks->items())),
                'links' => $tasks->linkCollection()->toArray(),
                'currentPage' => $tasks->currentPage(),
                'lastPage' => $tasks->lastPage(),
                'total' => $tasks->total(),
            ],
            'filters' => ['all' => $showAll],
            'racks' => Rack::query()->orderBy('name')->get()
                ->map(fn (Rack $rack): array => ['id' => $rack->id, 'name' => $rack->name])
                ->values()->all(),
            'servers' => Server::query()->orderBy('name')->get()
                ->map(fn (Server $server): array => ['id' => $server->id, 'name' => $server->name])
                ->values()->all(),
            'parts' => HardwarePart::query()->orderBy('serial')->limit(200)->get()
                ->map(fn (HardwarePart $part): array => [
                    'id' => $part->id,
                    'name' => $part->displayName(),
                ])->values()->all(),
            'can' => [
                'request' => $actor->can('dcim.remote_hands.request'),
                'complete' => $actor->can('dcim.remote_hands.complete'),
            ],
        ]);
    }

    public function store(Request $request, CurrentActor $actor): RedirectResponse
    {
        $this->refuseUnless($actor, 'dcim.remote_hands.request');

        $data = $request->validate([
            'summary' => ['required', 'string', 'max:160'],
            // Required, because a task with no instructions is somebody
            // standing in an aisle telephoning to ask what to do.
            'instructions' => ['required', 'string', 'max:4000'],
            'rack_id' => ['nullable', 'string', 'exists:racks,id'],
            'server_id' => ['nullable', 'string', 'exists:servers,id'],
            'hardware_part_id' => ['nullable', 'string', 'exists:hardware_parts,id'],
        ]);

        $staff = $this->staff($actor);

        $this->tasks->request($staff->organization_id, [
            'summary' => $data['summary'],
            'instructions' => $data['instructions'],
            // `validate()` returns only the keys that were submitted, so a
            // field the form left empty is absent rather than null.
            'rack_id' => $data['rack_id'] ?? null,
            'server_id' => $data['server_id'] ?? null,
            'hardware_part_id' => $data['hardware_part_id'] ?? null,
        ], $staff);

        return back()->with('status', __('dcim.remote_hands.requested'));
    }

    public function transition(Request $request, CurrentActor $actor, RemoteHandsTask $task): RedirectResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::enum(RemoteHandsState::class)],
            'technician' => ['nullable', 'string', 'max:120'],
            'scheduled_for' => ['nullable', 'date'],
            'old_serial' => ['nullable', 'string', 'max:191'],
            'new_serial' => ['nullable', 'string', 'max:191'],
            'outcome' => ['nullable', 'string', 'max:4000'],
            'evidence' => ['nullable', 'string', 'max:2048'],
        ]);

        $to = RemoteHandsState::from($data['state']);

        // Closing one is the technician's half and its own permission: the
        // serials are the register for the next warranty claim.
        $this->refuseUnless(
            $actor,
            $to === RemoteHandsState::Done ? 'dcim.remote_hands.complete' : 'dcim.remote_hands.request',
        );

        unset($data['state']);

        try {
            $this->tasks->transition($task, $to, $this->staff($actor), array_filter(
                $data,
                static fn (mixed $value): bool => $value !== null,
            ));
        } catch (RemoteHandsRefused $refused) {
            // Every refusal reaches the form as a sentence in the
            // operator's own language. Anything else still reaches the
            // handler, because anything else is a bug.
            return back()->withErrors([
                'state' => __($refused->key(), $refused->replacements()),
            ]);
        }

        return back()->with('status', __('dcim.remote_hands.moved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RemoteHandsTask $task): array
    {
        return [
            'id' => $task->id,
            'summary' => $task->summary,
            'instructions' => $task->instructions,
            'state' => $task->state->value,
            'stateLabel' => (string) __($task->state->labelKey()),
            'stateTone' => $task->state->tone(),
            'next' => array_map(
                // The wording of the button, not of the state: a button is
                // labelled with what it does.
                static fn (RemoteHandsState $state): array => [
                    'value' => $state->value,
                    'label' => (string) __($state->actionKey()),
                ],
                $task->state->next(),
            ),
            'rack' => $task->rack?->name,
            'server' => $task->server?->name,
            'part' => $task->part?->displayName(),
            'requestedBy' => $task->requester?->name,
            'requestedAt' => $task->requested_at->toIso8601String(),
            'scheduledFor' => $task->scheduled_for?->toIso8601String(),
            'completedAt' => $task->completed_at?->toIso8601String(),
            'technician' => $task->technician,
            'oldSerial' => $task->old_serial,
            'newSerial' => $task->new_serial,
            'outcome' => $task->outcome,
            'evidence' => $task->evidence,
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
