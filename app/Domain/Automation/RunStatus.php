<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * What became of a run.
 *
 * `Completed` means the run finished, not that every row succeeded — a
 * sweep that hit fifteen failures and carried on is a completed run with
 * fifteen failures, and calling it failed would hide the forty-five that
 * worked. `Failed` is reserved for a run that could not finish at all.
 */
enum RunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function labelKey(): string
    {
        return 'automation.status.'.$this->value;
    }

    public function isFinished(): bool
    {
        return $this !== self::Running;
    }
}
