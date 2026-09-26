<?php

declare(strict_types=1);

namespace App\Infrastructure\Reliability\Models;

use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\AlertState;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that is wrong, for as long as it is wrong.
 *
 * **One row per rule and subject while it is open**, enforced by a unique
 * index on `(alert_rule_id, subject_key, dedupe_token)`. The token exists
 * because MariaDB treats nulls in a unique index as distinct: a key ending in
 * `cleared_at` would allow two open rows and look as though it did not.
 *
 * `occurrences` is what makes the list readable. A disk that crosses 90% every
 * minute for six hours is one alert that has been seen 360 times, which is a
 * sentence an operator can act on — 360 rows is a list they close.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $alert_rule_id
 * @property string $subject_key
 * @property string $subject_label
 * @property AlertState $state
 * @property AlertSeverity $severity
 * @property string|null $observed
 * @property int $occurrences
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $cleared_at
 * @property string|null $suppressed_by
 * @property string|null $incident_id
 * @property string $dedupe_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Alert extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'alert_rule_id',
        'subject_key',
        'subject_label',
        'state',
        'severity',
        'observed',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'cleared_at',
        'suppressed_by',
        'incident_id',
        'dedupe_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'occurrences' => 1,
        'dedupe_token' => '',
    ];

    /**
     * @return BelongsTo<AlertRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function isOpen(): bool
    {
        return $this->cleared_at === null;
    }

    /**
     * How long it has been going on, in seconds.
     *
     * Measured to when it cleared, or to now while it is open — the opposite
     * of `DdosEvent::durationSeconds()`, and deliberately: an attack's
     * duration is a fact somebody quotes, and an alert's age is the thing an
     * operator is deciding about right now.
     */
    public function ageSeconds(): int
    {
        $end = $this->cleared_at ?? CarbonImmutable::now();

        return (int) $this->first_seen_at->diffInSeconds($end, absolute: true);
    }

    public function auditLabel(): string
    {
        return $this->subject_label;
    }

    /**
     * Still true.
     *
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => AlertState::class,
            'severity' => AlertSeverity::class,
            'occurrences' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }
}
