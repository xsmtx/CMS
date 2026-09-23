<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Where a run got to.
 *
 * `Completed` means the run finished, **not** that every row succeeded — the
 * same distinction the automation runs make (ADR 0031). A migration with four
 * hundred failures out of twelve thousand completed; it is the report that says
 * what to do next, not the status.
 *
 * `Failed` is reserved for a run that could not proceed at all: the legacy
 * database was unreachable, or a domain was asked for whose parent had never
 * been imported.
 */
enum ImportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function labelKey(): string
    {
        return 'import.statuses.'.$this->value;
    }

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
