<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * What happened to one legacy row.
 *
 * Four outcomes, and `Skipped` is the one that earns its place: a row already
 * mapped from an earlier run is not a success and not a failure, it is work
 * that was already done. Collapsing it into either would make a resumed import
 * report twelve thousand creations the second time, or twelve thousand
 * failures.
 */
enum ImportOutcome: string
{
    case Created = 'created';

    /** A mapped row whose details had changed on the legacy side. */
    case Updated = 'updated';

    /** Already imported, or deliberately not wanted. */
    case Skipped = 'skipped';

    case Failed = 'failed';

    public function labelKey(): string
    {
        return 'import.outcomes.'.$this->value;
    }
}
