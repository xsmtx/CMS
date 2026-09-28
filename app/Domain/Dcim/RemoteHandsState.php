<?php

declare(strict_types=1);

namespace App\Domain\Dcim;

/**
 * Where a remote-hands task has got to (§11).
 *
 * **`Scheduled` is its own state because somebody is waiting on a window.**
 * A task that has been agreed for Tuesday at two is not the same as one
 * nobody has looked at, and an operator chasing the datacenter needs to be
 * able to tell them apart without reading every note.
 *
 * **There is no `Failed`.** A technician who went and could not do it has
 * still been, and the outcome says what happened — which is the sentence
 * somebody needs, where a state would only say that they need to read it. A
 * task that should not have been asked for is `Cancelled`.
 */
enum RemoteHandsState: string
{
    case Requested = 'requested';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return 'dcim.remote_hands.states.'.$this->value;
    }

    /**
     * The wording of the button that *moves* a task into this state.
     *
     * Separate from `labelKey()` on purpose: a button is labelled with what
     * it does, not with the state it lands in. “Agreed” on a button reads as
     * a fact somebody is asserting rather than an action — the browser pass
     * found all three of these reading that way in a row.
     */
    public function actionKey(): string
    {
        return 'dcim.remote_hands.moves.'.$this->value;
    }

    /**
     * A word `status.ts` knows, pinned for every enum by `VocabularyTest`.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Scheduled => 'info',
            self::InProgress => 'maintenance',
            self::Done => 'healthy',
            self::Cancelled => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::Requested, self::Scheduled, self::InProgress => true,
            self::Done, self::Cancelled => false,
        };
    }

    /**
     * Which states a task may move to from here.
     *
     * Stated rather than inferred, and deliberately permissive in one place:
     * a task may go back from `Scheduled` to `Requested`, because a window
     * that falls through is an ordinary Tuesday and somebody would otherwise
     * cancel and re-raise it, losing the thread.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Requested => [self::Scheduled, self::InProgress, self::Cancelled],
            self::Scheduled => [self::Requested, self::InProgress, self::Cancelled],
            self::InProgress => [self::Done, self::Cancelled],
            self::Done, self::Cancelled => [],
        };
    }
}
