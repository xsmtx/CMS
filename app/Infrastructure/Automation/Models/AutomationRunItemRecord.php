<?php

declare(strict_types=1);

namespace App\Infrastructure\Automation\Models;

use App\Domain\Automation\ItemOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\AutomationRunItemRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row a run changed or failed on.
 *
 * Skips are counted, never written. A nightly sweep of ten thousand
 * services that changes four of them writes four rows.
 *
 * `subject_label` is a copy rather than a join, for the same reason the
 * delivery log copies an address: the line has to stay readable after the
 * service it describes is deleted.
 *
 * @property ItemOutcome $outcome
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property string|null $message
 * @property CarbonImmutable|null $created_at
 */
final class AutomationRunItemRecord extends Model
{
    /** @use HasFactory<AutomationRunItemRecordFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'automation_run_items';

    protected $fillable = [
        'run_id',
        'subject_type',
        'subject_id',
        'subject_label',
        'outcome',
        'message',
        'created_at',
    ];

    /**
     * @return BelongsTo<AutomationRunRecord, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRunRecord::class, 'run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => ItemOutcome::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
