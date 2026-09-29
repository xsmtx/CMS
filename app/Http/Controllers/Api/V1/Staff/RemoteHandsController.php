<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Application\Dcim\RemoteHands;
use App\Domain\Dcim\Exceptions\RemoteHandsRefused;
use App\Domain\Dcim\RemoteHandsState;
use App\Http\Controllers\Controller;
use App\Infrastructure\Dcim\Models\RemoteHandsTask;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Errors\ForbiddenException;
use App\Support\Errors\ValidationFailedException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The queue a technician actually works from (§26).
 *
 * This is the one part of the staff app that is genuinely better on a phone
 * than on a desk: the person doing it is standing in front of a rack. Hence
 * the instructions come down in full rather than truncated, and moving a task
 * goes through `RemoteHands::transition()` — the one place that knows which
 * moves exist and which clock each one stamps.
 */
final class RemoteHandsController extends Controller
{
    public function __construct(
        private readonly RemoteHands $tasks,
        private readonly CurrentActor $actor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $open = $request->boolean('open', true);

        $tasks = RemoteHandsTask::query()
            ->with(['rack', 'server'])
            ->when($open, fn ($query) => $query->whereNotIn('state', [
                RemoteHandsState::Done->value,
                RemoteHandsState::Cancelled->value,
            ]))
            // Oldest first: a queue is worked from the top, and the thing
            // waiting longest is the thing somebody is waiting on.
            ->oldest('requested_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $tasks->map(fn (RemoteHandsTask $task): array => [
                'id' => $task->id,
                'summary' => $task->summary,
                // In full. Half an instruction is worse than none to
                // somebody standing in a cold aisle.
                'instructions' => $task->instructions,
                'state' => $task->state->value,
                'stateLabel' => (string) __($task->state->labelKey()),
                'rack' => $task->rack?->name,
                'server' => $task->server?->hostname,
                'technician' => $task->technician,
                'requestedAt' => $task->requested_at->toIso8601String(),
                'scheduledFor' => $task->scheduled_for?->toIso8601String(),
                // What this task may become next, so a phone can offer the
                // moves that exist rather than guessing at them.
                'moves' => array_map(
                    static fn (RemoteHandsState $state): array => [
                        'value' => $state->value,
                        'label' => (string) __($state->actionKey()),
                    ],
                    $task->state->next(),
                ),
            ])->values(),
        ]);
    }

    public function move(Request $request, RemoteHandsTask $task): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'string', Rule::in(array_column(RemoteHandsState::cases(), 'value'))],
            'technician' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $staff = $this->actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException((string) __('api.errors.forbidden'));
        }

        try {
            $this->tasks->transition(
                task: $task,
                to: RemoteHandsState::from($validated['state']),
                actor: $staff,
                attributes: array_filter([
                    'technician' => $validated['technician'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            );
        } catch (RemoteHandsRefused $refused) {
            // A move that does not exist is a caller's mistake, so it is a
            // 422 with the sentence rather than a 500.
            throw new ValidationFailedException((string) __($refused->key(), $refused->replacements()));
        }

        return response()->json(['data' => ['state' => $task->refresh()->state->value]]);
    }
}
