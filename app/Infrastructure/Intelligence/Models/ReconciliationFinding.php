<?php

declare(strict_types=1);

namespace App\Infrastructure\Intelligence\Models;

use App\Domain\Intelligence\ReconciliationClass;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ReconciliationFindingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One difference between what this platform believes and what a provider
 * reports (§22).
 *
 * Raised once, kept while it stays true, cleared rather than deleted — the
 * shape `alerts`, `zone_findings` and `reputation_listings` already share.
 * The cleared row answers "how long was that account suspended without us
 * knowing", which is the question asked while writing to the customer.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $subject_label
 * @property string|null $remote_key
 * @property string $resource
 * @property ReconciliationClass $class
 * @property string|null $field
 * @property string|null $expected
 * @property string|null $found
 * @property array<string, mixed>|null $detail
 * @property string $source
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $cleared_at
 * @property string $cleared_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ReconciliationFinding extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ReconciliationFindingFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'subject_type',
        'subject_id',
        'subject_label',
        'remote_key',
        'resource',
        'class',
        'field',
        'expected',
        'found',
        'detail',
        'source',
        'first_seen_at',
        'last_seen_at',
        'cleared_at',
        'cleared_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cleared_token' => '',
    ];

    /**
     * The thing this is about, where there is one here at all.
     *
     * Null for an orphan, which is the whole of what an orphan is.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function auditLabel(): string
    {
        return $this->subject_label;
    }

    /**
     * What has been suggested or decided about it.
     *
     * @return HasMany<RemediationProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(RemediationProposal::class, 'reconciliation_finding_id');
    }

    /**
     * Still somebody's to answer.
     *
     * @param  Builder<ReconciliationFinding>  $query
     * @return Builder<ReconciliationFinding>
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
            'class' => ReconciliationClass::class,
            'detail' => 'array',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
