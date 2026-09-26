<?php

declare(strict_types=1);

namespace App\Infrastructure\Network\Models;

use App\Domain\Infrastructure\Network\DdosVector;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\DdosEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attack on one address, and whose it was.
 *
 * Running is `ended_at === null`, not a status column — the same reasoning as
 * `AccessGrant`: a state that has to be kept true by something running on
 * time is a state that is wrong whenever that thing is not.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $source
 * @property string $reference
 * @property string $target_address
 * @property string|null $ip_address_id
 * @property string|null $customer_id
 * @property string|null $service_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $peak_gbps
 * @property string|null $peak_mpps
 * @property list<string>|null $vectors
 * @property string|null $mitigation
 * @property string|null $incident_reference
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class DdosEvent extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<DdosEventFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'source',
        'reference',
        'target_address',
        'ip_address_id',
        'customer_id',
        'service_id',
        'started_at',
        'ended_at',
        'peak_gbps',
        'peak_mpps',
        'vectors',
        'mitigation',
        'incident_reference',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<IpAddressRecord, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(IpAddressRecord::class, 'ip_address_id');
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * How long it went on, in seconds, or null while it is still going.
     *
     * Null rather than "so far": a duration that grows every time somebody
     * refreshes is a number two operators would quote differently in the same
     * minute.
     */
    public function durationSeconds(): ?int
    {
        return $this->ended_at === null
            ? null
            : (int) $this->started_at->diffInSeconds($this->ended_at, absolute: true);
    }

    /**
     * The shapes it took, as enum members.
     *
     * A vector the column holds that this version has no member for is
     * dropped rather than guessed at — the same rule `MetricKind` follows for
     * a metric name core does not know.
     *
     * @return list<DdosVector>
     */
    public function vectorCases(): array
    {
        return array_values(array_filter(array_map(
            DdosVector::tryFrom(...),
            $this->vectors ?? [],
        )));
    }

    public function auditLabel(): string
    {
        return $this->target_address;
    }

    /**
     * Still going.
     *
     * @param  Builder<DdosEvent>  $query
     * @return Builder<DdosEvent>
     */
    protected function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'vectors' => 'array',
        ];
    }
}
