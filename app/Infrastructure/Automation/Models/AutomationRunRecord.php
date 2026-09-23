<?php

declare(strict_types=1);

namespace App\Infrastructure\Automation\Models;

use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunStatus;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\AutomationRunRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of one task.
 *
 * Deliberately not an owned model. A run sweeps the whole installation and
 * touches rows belonging to many organizations; stamping it with one would
 * be a lie, and stamping it with the provider's would make a reseller's
 * screen show runs that also changed somebody else's rows.
 *
 * @property AutomationTask $task
 * @property RunStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int $examined
 * @property int $changed
 * @property int $skipped
 * @property int $failed
 * @property string|null $correlation_id
 * @property string|null $error
 */
final class AutomationRunRecord extends Model implements AuditLabel
{
    /** @use HasFactory<AutomationRunRecordFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'automation_runs';

    protected $fillable = [
        'task',
        'status',
        'triggered_by',
        'started_at',
        'finished_at',
        'examined',
        'changed',
        'skipped',
        'failed',
        'correlation_id',
        'error',
    ];

    /**
     * The counters have database defaults, which fill the row but leave the
     * model in memory without the attribute — and an integer cast reads
     * that absence as null.
     *
     * @var array<string, int|string>
     */
    protected $attributes = [
        'examined' => 0,
        'changed' => 0,
        'skipped' => 0,
        'failed' => 0,
        'status' => 'running',
    ];

    /**
     * @return HasMany<AutomationRunItemRecord, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AutomationRunItemRecord::class, 'run_id');
    }

    public function duration(): ?int
    {
        if ($this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }

    public function auditLabel(): string
    {
        return $this->task->value;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeForTask(Builder $query, AutomationTask $task): Builder
    {
        return $query->where('task', $task->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AutomationTask::class,
            'status' => RunStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'examined' => 'integer',
            'changed' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
