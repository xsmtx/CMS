<?php

declare(strict_types=1);

namespace App\Domain\Platform;

/**
 * Where a note to come back to something is.
 *
 * Three states and no more. A todo list with a workflow is a ticket system
 * with worse reporting, and this one exists so that "ring the registrar
 * about the .com.tr transfer" has somewhere to live that is not a sticky
 * note on a monitor.
 */
enum TodoStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function labelKey(): string
    {
        return 'platform.todo.statuses.'.$this->value;
    }

    public function isOpen(): bool
    {
        return $this !== self::Done;
    }
}
