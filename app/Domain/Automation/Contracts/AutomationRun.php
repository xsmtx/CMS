<?php

declare(strict_types=1);

namespace App\Domain\Automation\Contracts;

use App\Domain\Automation\RunSummary;

/**
 * One task that runs on its own.
 *
 * Every implementation obeys the same three rules, and each of them is
 * tested for rather than trusted:
 *
 * 1. **It asks a question about state, never about elapsed time.** "Which
 *    services are past due and not suspended" survives a server that was
 *    switched off for two days; "which became overdue today" loses a day's
 *    work permanently.
 * 2. **It is safe to run twice.** Running it again immediately must change
 *    nothing, which follows from rule 1 wherever the state itself records
 *    that the work happened.
 * 3. **One row failing never stops it.** Each row is its own try/catch and
 *    its own line in the summary.
 *
 * The run never writes its own record — `RecordedRun` does that around it,
 * so a task cannot forget.
 */
interface AutomationRun
{
    public function handle(): RunSummary;
}
