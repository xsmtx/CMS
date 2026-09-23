<?php

declare(strict_types=1);

namespace App\Application\Automation;

use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunStatus;
use App\Domain\Automation\RunSummary;
use App\Infrastructure\Automation\Models\AutomationRunItemRecord;
use App\Infrastructure\Automation\Models\AutomationRunRecord;
use App\Support\Correlation\CorrelationContext;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Runs a task and writes down what it did.
 *
 * Wrapped around the task rather than mixed into it, so a task cannot
 * forget: there is exactly one path from "run this" to a row in
 * `automation_runs`, and it is this one.
 *
 * Three things are deliberate here.
 *
 * **The record is written before the task starts.** A run that dies half
 * way — killed process, out of memory — leaves a row stuck in `running`
 * rather than no evidence that anything was attempted. A stuck row is a
 * question an operator can ask; silence is not.
 *
 * **A run that did nothing is still written.** "Examined 60, changed 0" is
 * the answer to "why was nobody suspended last night", and a recorder that
 * only writes interesting runs cannot give it.
 *
 * **The error is redacted before it is stored.** A provider exception
 * message is the most likely place a credential reaches a database column,
 * and this column is read on a screen.
 */
final readonly class RecordedRun
{
    public function __construct(
        private CorrelationContext $correlation,
        private SecretRedactor $redactor,
    ) {}

    public function handle(
        AutomationTask $task,
        AutomationRun $run,
        ?Model $actor = null,
    ): AutomationRunRecord {
        $record = AutomationRunRecord::query()->create([
            'task' => $task->value,
            'status' => RunStatus::Running->value,
            'triggered_by' => $actor instanceof Model ? $actor->getKey() : null,
            'started_at' => CarbonImmutable::now(),
            'correlation_id' => $this->correlation->idOrGenerate(),
        ]);

        try {
            $summary = $run->handle();
        } catch (Throwable $exception) {
            // The task could not finish at all, which is a different thing
            // from a run in which some rows failed.
            $record->update([
                'status' => RunStatus::Failed->value,
                'finished_at' => CarbonImmutable::now(),
                'error' => $this->redactor->redactString($exception->getMessage()),
            ]);

            return $record->refresh();
        }

        $this->writeItems($record, $summary);

        $record->update([
            'status' => RunStatus::Completed->value,
            'finished_at' => CarbonImmutable::now(),
            'examined' => $summary->examined,
            'changed' => $summary->changed,
            'skipped' => $summary->skipped,
            'failed' => $summary->failed,
        ]);

        return $record->refresh();
    }

    private function writeItems(AutomationRunRecord $record, RunSummary $summary): void
    {
        foreach ($summary->items as $item) {
            if ($item->outcome === ItemOutcome::Skipped) {
                // Counted on the run, never written. Ten thousand rows
                // nobody reads is not a record, it is a landfill.
                continue;
            }

            $this->writeItem($record, $item);
        }
    }

    private function writeItem(AutomationRunRecord $record, RunItem $item): void
    {
        AutomationRunItemRecord::query()->create([
            'run_id' => $record->id,
            'subject_type' => $item->subjectType,
            'subject_id' => $item->subjectId,
            'subject_label' => $item->subjectLabel,
            'outcome' => $item->outcome->value,
            'message' => $item->message === null
                ? null
                : $this->redactor->redactString($item->message),
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
