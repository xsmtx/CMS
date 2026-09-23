<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Domain\Operations\OperationState;
use App\Infrastructure\Operations\Models\Operation;

/**
 * What the operations drawer shows, and what the topbar counts.
 *
 * An operation is visible before it finishes (ADR 0032), and the Background
 * Operations Center answers that for somebody who went looking. The drawer
 * answers it for somebody who did not: the point of a count in the chrome is
 * that an operator finds out a provisioning run failed without opening a
 * screen to ask.
 *
 * Two methods because they cost different amounts and are wanted at
 * different times:
 *
 * - `summary()` is two counts over `operations_state_index`, and it runs on
 *   every admin page render. That is a real cost and it is the only one
 *   worth paying: a badge that is always grey is worse than no badge, so the
 *   number has to be current, and nothing cheaper than the column is.
 * - `recent()` builds rows and is asked for only when the drawer opens.
 */
final readonly class OperationFeed
{
    /**
     * The two numbers the chrome shows.
     *
     * `attention` is deliberately the same question the Operations screen
     * opens on, so the badge and the screen can never disagree about how
     * many problems there are.
     *
     * @return array{active: int, attention: int}
     */
    public function summary(): array
    {
        return [
            'active' => Operation::query()
                ->whereIn('state', [
                    OperationState::Pending->value,
                    OperationState::Running->value,
                    OperationState::Retrying->value,
                ])
                ->count(),
            'attention' => Operation::query()->needingAttention()->count(),
        ];
    }

    /**
     * The drawer's rows: what is happening now, newest first.
     *
     * Unfinished rows before finished ones, because the drawer is asked
     * "what is going on" rather than "what happened". A completed run is
     * still listed — an operator who came to check on something that has
     * just succeeded should see that it succeeded, not an empty drawer.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 12): array
    {
        $rows = Operation::query()
            ->orderByRaw('case when finished_at is null then 0 else 1 end')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Operation $operation): array => [
                'id' => $operation->id,
                'typeLabel' => (string) __($operation->type->labelKey()),
                'state' => $operation->state->value,
                'stateLabel' => (string) __($operation->state->labelKey()),
                'subject' => $operation->subject_label,
                'attempt' => $operation->attempt,
                'maxAttempts' => $operation->max_attempts,
                'progress' => $operation->progress,
                // §8 asks for the correlation ID on this drawer by name. It
                // is the one string that ties a failure here to the lines in
                // the log, and an operator pastes it into both.
                'correlationId' => $operation->correlation_id,
                // Already redacted on the way in by `SecretRedactor`. Shown
                // because an operator cannot act on "something went wrong".
                'error' => $operation->error,
                'needsAttention' => $operation->state->needsAttention()
                    && $operation->resolved_at === null,
                'startedAt' => $operation->started_at?->toIso8601String(),
                'finishedAt' => $operation->finished_at?->toIso8601String(),
                'createdAt' => $operation->created_at?->toIso8601String(),
            ])
            ->all();

        // `array_values` rather than the collection's own `values()`: only
        // the first tells the analyser the keys are a list again.
        return array_values($rows);
    }
}
