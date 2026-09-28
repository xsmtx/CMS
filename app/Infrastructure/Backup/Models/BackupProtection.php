<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup\Models;

use App\Domain\Infrastructure\Backup\BackupOutcome;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\BackupProtectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a backup source says it is protecting (§12).
 *
 * There is no state column. Whether this is stale is a question about
 * `last_good_at` and the clock, and how old is too old is the operator's
 * threshold on an alert rule — a nightly job and a weekly one do not agree,
 * and a stored state would be one some scheduler run decided.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $source
 * @property string $resource_key
 * @property string $resource_name
 * @property string|null $resource_type
 * @property string|null $repository
 * @property BackupOutcome $last_outcome
 * @property CarbonImmutable|null $last_run_at
 * @property CarbonImmutable|null $last_good_at
 * @property int|null $restore_points
 * @property int|null $size_bytes
 * @property string|null $service_id
 * @property string|null $customer_id
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $retired_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BackupProtection extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<BackupProtectionFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'source',
        'resource_key',
        'resource_name',
        'resource_type',
        'repository',
        'last_outcome',
        'last_run_at',
        'last_good_at',
        'restore_points',
        'size_bytes',
        'service_id',
        'customer_id',
        'first_seen_at',
        'last_seen_at',
        'retired_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'last_outcome' => 'unknown',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * How many days old the last good copy is, or null when there has never
     * been one.
     *
     * Null is not "very old": a resource added to a job this afternoon that
     * has not run yet has never had a good copy and is not a failure. The
     * screen says so in words and the alert rule skips it, because a number
     * invented here would be a number somebody acts on.
     */
    public function lastGoodAgeInDays(?CarbonImmutable $now = null): ?int
    {
        if (! $this->last_good_at instanceof CarbonImmutable) {
            return null;
        }

        return (int) $this->last_good_at->diffInDays($now ?? CarbonImmutable::now());
    }

    public function auditLabel(): string
    {
        return $this->resource_name;
    }

    /**
     * Still named by its source.
     *
     * @param  Builder<BackupProtection>  $query
     * @return Builder<BackupProtection>
     */
    protected function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_outcome' => BackupOutcome::class,
            'last_run_at' => 'immutable_datetime',
            'last_good_at' => 'immutable_datetime',
            'restore_points' => 'integer',
            'size_bytes' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
