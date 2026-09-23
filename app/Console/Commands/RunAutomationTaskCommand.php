<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Automation\RecordedRun;
use App\Application\Automation\TaskRegistry;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunStatus;
use Illuminate\Console\Command;

/**
 * Runs one automation task and prints what it did.
 *
 * The same entry point the scheduler uses, which is deliberate: an operator
 * learning to trust a task runs it by hand first and watches, and a task
 * that behaves differently under the scheduler than under a person is a
 * task nobody will ever trust.
 *
 * The exit code is non-zero only when the run itself failed. A run in which
 * some rows failed exits zero and says so — a nightly cron that alerts
 * because one of four hundred services could not be reached is a cron whose
 * alerts get filtered.
 */
final class RunAutomationTaskCommand extends Command
{
    protected $signature = 'platform:run {task : One of the automation tasks, or "all"}';

    protected $description = 'Run an automation task and record what it did';

    public function handle(RecordedRun $runner, TaskRegistry $registry): int
    {
        $requested = (string) $this->argument('task');

        $tasks = $requested === 'all'
            ? AutomationTask::cases()
            : array_filter([AutomationTask::tryFrom($requested)]);

        if ($tasks === []) {
            $this->components->error(sprintf(
                'Unknown task [%s]. Known: %s.',
                $requested,
                implode(', ', array_column(AutomationTask::cases(), 'value')),
            ));

            return self::FAILURE;
        }

        $failed = false;

        foreach ($tasks as $task) {
            $record = $runner->handle($task, $registry->resolve($task));

            $this->components->twoColumnDetail(
                $task->value,
                sprintf(
                    '%s — examined %d, changed %d, skipped %d, failed %d',
                    $record->status->value,
                    $record->examined,
                    $record->changed,
                    $record->skipped,
                    $record->failed,
                ),
            );

            if ($record->status === RunStatus::Failed) {
                $this->components->error($record->error ?? 'The run could not finish.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
