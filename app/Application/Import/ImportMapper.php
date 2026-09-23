<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;

/**
 * Turns one legacy row into one of this platform's records.
 *
 * One mapper per domain, resolved by domain, so adding a domain is adding a
 * class rather than editing a `match` somebody will forget.
 *
 * The interface lives in `Application` rather than in `Domain` because it has
 * to name `ImportWriter` and `ImportResult`, which are use-case collaborators.
 * "What a legacy row is" is a domain concept and `ImportRecord` is where it
 * lives; "how a row becomes a record here" is a use case.
 *
 * **A mapper never writes.** It builds attributes and hands a closure to the
 * writer, which is the single place that decides whether anything is written at
 * all — that is what makes the dry run the same code path.
 *
 * **A mapper returns a failure rather than throwing** when the row itself is
 * the problem: an unknown currency, a missing parent, a name nobody can read.
 * The reason is shown to an operator, so it says what is wrong with the row
 * rather than what threw.
 */
interface ImportMapper
{
    public function domain(): ImportDomain;

    public function map(ImportRecord $record, ImportWriter $writer): ImportResult;
}
