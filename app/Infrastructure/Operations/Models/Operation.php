<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Models;

use App\Domain\Operations\OperationState;
use App\Domain\Operations\OperationType;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\OperationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A long-running business operation, visible before it finishes.
 *
 * Written *before* the job is dispatched, which is the whole point: an
 * operation that never reaches a worker — because Redis was down, or the
 * queue was not being watched — is still a row an operator can see. A queue
 * job that never ran leaves nothing at all.
 *
 * Owned, unlike an automation run: an operation is always about one
 * customer's service or domain, so it has exactly one organization and
 * belongs inside the boundary.
 *
 * @property OperationType $type
 * @property OperationState $state
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property int $attempt
 * @property int $max_attempts
 * @property int $progress
 * @property string|null $correlation_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property string|null $error
 * @property bool $needs_intervention
 * @property CarbonImmutable|null $resolved_at
 */
final class Operation extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<OperationFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'operations';

    protected $fillable = [
        'organization_id',
        'type',
        'state',
        'subject_type',
        'subject_id',
        'subject_label',
        'actor_type',
        'actor_id',
        'attempt',
        'max_attempts',
        'progress',
        'correlation_id',
        'started_at',
        'finished_at',
        'next_attempt_at',
        'error',
        'needs_intervention',
        'resolved_at',
        'resolved_by',
    ];

    /**
     * Database defaults do not reach the model in memory, and a cast reads
     * the absence as null.
     *
     * @var array<string, bool|int|string>
     */
    protected $attributes = [
        'state' => 'pending',
        'attempt' => 0,
        'max_attempts' => 3,
        'progress' => 0,
        'needs_intervention' => false,
    ];

    public function hasAttemptsLeft(): bool
    {
        return $this->attempt < $this->max_attempts;
    }

    public function auditLabel(): string
    {
        return $this->type->value.($this->subject_label === null ? '' : ' — '.$this->subject_label);
    }

    /**
     * The rows an operator opens this screen for.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereIn('state', [
            OperationState::Failed->value,
            OperationState::ManualIntervention->value,
        ])->whereNull('resolved_at');
    }

    /**
     * The retry sweep's question: retrying, due, and not out of attempts.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeDueForRetry(Builder $query): Builder
    {
        return $query->where('state', OperationState::Retrying->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', CarbonImmutable::now())
            ->whereColumn('attempt', '<', 'max_attempts');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OperationType::class,
            'state' => OperationState::class,
            'attempt' => 'integer',
            'max_attempts' => 'integer',
            'progress' => 'integer',
            'needs_intervention' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
