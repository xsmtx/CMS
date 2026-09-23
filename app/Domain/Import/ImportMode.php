<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Whether this run writes anything.
 *
 * **A dry run is the same code path with one flag**, checked in one place — the
 * writer. A dry run that took a different path would be a dry run that proves
 * nothing, which is the failure mode every dry run has: it passes, the real
 * import fails, and the operator has already told their customers.
 */
enum ImportMode: string
{
    /** Read, map, validate, report. Write nothing. */
    case DryRun = 'dry_run';

    case Live = 'live';

    public function writes(): bool
    {
        return $this === self::Live;
    }

    public function labelKey(): string
    {
        return 'import.modes.'.$this->value;
    }
}
