<?php

declare(strict_types=1);

namespace App\Application\Dcim;

use App\Domain\Dcim\Exceptions\RemoteHandsRefused;
use App\Domain\Dcim\RemoteHandsState;
use App\Infrastructure\Dcim\Models\RemoteHandsTask;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;

/**
 * Asking somebody to go and touch a machine, and recording what they found
 * (§11).
 *
 * **One place moves the state and the clock**, which is the rule
 * `TransitionTicket` set: two places that can set `completed_at` is one too
 * many. Every move writes an audit row, because the record is the reason the
 * table exists — an audit that says a machine was opened is worth more than a
 * ticket saying somebody was asked to open it.
 *
 * **A move that is not on the list is refused by name.** The list is on
 * `RemoteHandsState::next()` and it is deliberately permissive in one place:
 * a task may go back from `Scheduled` to `Requested`, because a window that
 * falls through is an ordinary Tuesday and somebody would otherwise cancel
 * and re-raise it, losing the thread.
 *
 * **Finishing asks for the outcome.** A task closed with nothing written is a
 * technician's visit nobody can read afterwards, and that is the one sentence
 * the whole record exists for.
 */
final readonly class RemoteHands
{
    /**
     * @param  array{summary: string, instructions: string, rack_id?: string|null, server_id?: string|null, hardware_part_id?: string|null}  $attributes
     */
    public function request(string $organizationId, array $attributes, StaffUser $actor): RemoteHandsTask
    {
        $task = RemoteHandsTask::query()->create([
            'organization_id' => $organizationId,
            'summary' => $attributes['summary'],
            'instructions' => $attributes['instructions'],
            'rack_id' => $attributes['rack_id'] ?? null,
            'server_id' => $attributes['server_id'] ?? null,
            'hardware_part_id' => $attributes['hardware_part_id'] ?? null,
            'state' => RemoteHandsState::Requested,
            'requested_by' => $actor->id,
            'requested_at' => CarbonImmutable::now(),
        ]);

        Audit::action('dcim.remote_hands.requested')
            ->by($actor)
            ->on($task)
            ->forOrganization($organizationId)
            ->withMetadata(['summary' => $task->summary])
            ->write();

        return $task;
    }

    /**
     * Move it, and stamp whichever clock this move owns.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(
        RemoteHandsTask $task,
        RemoteHandsState $to,
        StaffUser $actor,
        array $attributes = [],
    ): RemoteHandsTask {
        if (! in_array($to, $task->state->next(), strict: true)) {
            throw RemoteHandsRefused::cannotMove(
                (string) __($task->state->labelKey()),
                (string) __($to->labelKey()),
            );
        }

        if ($to === RemoteHandsState::Done && trim((string) ($attributes['outcome'] ?? $task->outcome)) === '') {
            // The one sentence the whole record exists for.
            throw RemoteHandsRefused::needsOutcome();
        }

        $now = CarbonImmutable::now();
        $from = $task->state;

        $task->fill($attributes);
        $task->state = $to;

        // One place owns the clock. `started_at` and `completed_at` are set
        // here and nowhere else.
        match ($to) {
            RemoteHandsState::InProgress => $task->started_at ??= $now,
            RemoteHandsState::Done, RemoteHandsState::Cancelled => $task->completed_at = $now,
            default => null,
        };

        $task->save();

        Audit::action('dcim.remote_hands.'.$to->value)
            ->by($actor)
            ->on($task)
            ->forOrganization($task->organization_id)
            ->because(is_string($attributes['outcome'] ?? null) ? $attributes['outcome'] : null)
            ->withMetadata(array_filter([
                'from' => $from->value,
                'technician' => $task->technician,
                'old_serial' => $task->old_serial,
                'new_serial' => $task->new_serial,
            ], static fn (mixed $value): bool => $value !== null))
            ->write();

        return $task;
    }
}
