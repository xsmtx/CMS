<?php

declare(strict_types=1);

namespace App\Infrastructure\Reliability\Models;

use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\IncidentState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A human saying "this is a thing".
 *
 * The record an alert is not: a state somebody moves, a timeline somebody
 * writes, and at the end a figure that is frozen. Many alerts belong to one
 * incident and an incident may have none — half of them start with a
 * customer's phone call, and a platform that required an alert first would be
 * a platform where the worst outages could not be recorded.
 *
 * `started_at` and `detected_at` are not the same question. The first is when
 * the customer's world broke, which is what an SLA is measured from; the
 * second is when anybody found out, and the gap between them is what a
 * postmortem is usually about.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $reference
 * @property string $title
 * @property IncidentState $state
 * @property AlertSeverity $severity
 * @property string|null $opened_by
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $detected_at
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $summary
 * @property bool $is_public
 * @property string|null $postmortem
 * @property CarbonImmutable|null $postmortem_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Incident extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'reference',
        'title',
        'state',
        'severity',
        'opened_by',
        'started_at',
        'detected_at',
        'resolved_at',
        'summary',
        'is_public',
        'postmortem',
        'postmortem_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => 'investigating',
        'is_public' => false,
    ];

    /**
     * @return HasMany<IncidentUpdate, $this>
     */
    public function updates(): HasMany
    {
        /*
         * Newest first, and **tie-broken on the id**.
         *
         * `latest()` alone orders by `created_at`, which has a resolution of
         * one second — and several updates inside one minute is exactly what
         * a busy incident looks like. Two written in the same second then
         * came back in whatever order the database felt like, which on a
         * status page is a timeline that reads backwards. The id is a ULID,
         * so it sorts by the moment it was made and settles the tie with the
         * truth rather than with luck.
         */
        return $this->hasMany(IncidentUpdate::class)->latest()
            ->orderByDesc('id');
    }

    /**
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * @return HasOne<IncidentImpact, $this>
     */
    public function impact(): HasOne
    {
        return $this->hasOne(IncidentImpact::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'opened_by');
    }

    /**
     * How long it went on, in seconds, or null while it is still going.
     *
     * Measured from `started_at` rather than from detection, because that is
     * what an SLA is measured from — and the difference is the whole point of
     * carrying both.
     */
    public function durationSeconds(): ?int
    {
        return $this->resolved_at === null
            ? null
            : (int) $this->started_at->diffInSeconds($this->resolved_at, absolute: true);
    }

    public function auditLabel(): string
    {
        return $this->reference.' — '.$this->title;
    }

    /**
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', '!=', IncidentState::Resolved->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => IncidentState::class,
            'severity' => AlertSeverity::class,
            'started_at' => 'immutable_datetime',
            'detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'postmortem_at' => 'immutable_datetime',
            'is_public' => 'boolean',
        ];
    }
}
