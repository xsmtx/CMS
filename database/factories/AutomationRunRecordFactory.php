<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunStatus;
use App\Infrastructure\Automation\Models\AutomationRunRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRunRecord>
 */
final class AutomationRunRecordFactory extends Factory
{
    protected $model = AutomationRunRecord::class;

    public function definition(): array
    {
        return [
            'task' => AutomationTask::Renewals->value,
            'status' => RunStatus::Completed->value,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'examined' => 0,
            'changed' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
    }

    public function forTask(AutomationTask $task): self
    {
        return $this->state(fn (): array => ['task' => $task->value]);
    }

    public function failed(string $error = 'Could not reach the provider'): self
    {
        return $this->state(fn (): array => [
            'status' => RunStatus::Failed->value,
            'error' => $error,
        ]);
    }
}
