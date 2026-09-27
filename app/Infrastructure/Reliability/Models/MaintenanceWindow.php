<?php

declare(strict_types=1);

namespace App\Infrastructure\Reliability\Models;

use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\MaintenanceWindowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Planned work, and the notifications it holds back.
 *
 * **Whether it is running is a question about its own timestamps**, never a
 * stored state. A scheduler that was down for three hours must not leave a
 * window marked "scheduled" while it is plainly happening, and asking the
 * clock at the moment somebody asks the question is how that stays true
 * (ADR 0031, and the shape `access_grants` already uses). Being called off is
 * the one thing a clock cannot say, so `cancelled_at` is a column.
 *
 * **No node keys means the whole installation.** A datacentre power test is
 * the ordinary case, and making an operator enumerate four hundred machines
 * to express it is how a feature goes unused.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string|null $body
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $cancelled_at
 * @property bool $is_public
 * @property list<string> $node_keys
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MaintenanceWindow extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'title',
        'body',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'is_public',
        'node_keys',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_public' => false,
        'node_keys' => '[]',
    ];

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    /** Running right now, and not called off. */
    public function isActive(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $this->cancelled_at === null
            && $this->starts_at->lessThanOrEqualTo($at)
            && $this->ends_at->greaterThan($at);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function hasEnded(?CarbonImmutable $at = null): bool
    {
        return $this->ends_at->lessThanOrEqualTo($at ?? CarbonImmutable::now());
    }

    /**
     * Whether this window covers a given subject.
     *
     * An empty list is the whole installation, which is why this is a method
     * rather than an `in_array` at each call site: the empty case is the one
     * somebody writing the check by hand gets backwards.
     */
    public function covers(string $nodeKey): bool
    {
        return $this->node_keys === [] || in_array($nodeKey, $this->node_keys, strict: true);
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    /**
     * Running now. A range over both ends, which the index matches.
     *
     * @param  Builder<MaintenanceWindow>  $query
     * @return Builder<MaintenanceWindow>
     */
    protected function scopeActive(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        $at ??= CarbonImmutable::now();

        return $query->whereNull('cancelled_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    /**
     * Still to come.
     *
     * @param  Builder<MaintenanceWindow>  $query
     * @return Builder<MaintenanceWindow>
     */
    protected function scopeUpcoming(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        return $query->whereNull('cancelled_at')
            ->where('starts_at', '>', $at ?? CarbonImmutable::now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'is_public' => 'boolean',
            'node_keys' => 'array',
        ];
    }
}
