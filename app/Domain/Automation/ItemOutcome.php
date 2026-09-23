<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * What a run did to one row.
 *
 * `Skipped` carries a reason and is never written as a detail row — the
 * count on the run covers it. It exists as a member because the tasks
 * themselves have to say which of the three happened, and a boolean would
 * lose the difference between "nothing to do" and "tried and failed".
 */
enum ItemOutcome: string
{
    case Changed = 'changed';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function labelKey(): string
    {
        return 'automation.outcome.'.$this->value;
    }
}
