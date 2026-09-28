<?php

declare(strict_types=1);

namespace App\Infrastructure\Dcim\Models;

use App\Domain\Dcim\RemoteHandsState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\RemoteHandsTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody asked to go and touch a machine (§11).
 *
 * A record before it is a request. The technician is a name rather than a
 * staff user, because the person who walks to the rack works for the
 * datacenter and has no account here.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $rack_id
 * @property string|null $server_id
 * @property string|null $hardware_part_id
 * @property string $summary
 * @property string $instructions
 * @property RemoteHandsState $state
 * @property string|null $requested_by
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $scheduled_for
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $technician
 * @property string|null $old_serial
 * @property string|null $new_serial
 * @property string|null $outcome
 * @property string|null $evidence
 * @property string|null $network_change_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class RemoteHandsTask extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<RemoteHandsTaskFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'rack_id',
        'server_id',
        'hardware_part_id',
        'summary',
        'instructions',
        'state',
        'requested_by',
        'requested_at',
        'scheduled_for',
        'started_at',
        'completed_at',
        'technician',
        'old_serial',
        'new_serial',
        'outcome',
        'evidence',
        'network_change_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => 'requested',
    ];

    /**
     * @return BelongsTo<Rack, $this>
     */
    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<HardwarePart, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(HardwarePart::class, 'hardware_part_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'requested_by');
    }

    /**
     * @return BelongsTo<NetworkChange, $this>
     */
    public function change(): BelongsTo
    {
        return $this->belongsTo(NetworkChange::class, 'network_change_id');
    }

    /**
     * Whether a serial was swapped.
     *
     * Both or neither: a task that recorded what came out and not what went
     * in is a register with a hole in it, and the screen asks for both.
     */
    public function swappedSerials(): bool
    {
        return $this->old_serial !== null && $this->new_serial !== null;
    }

    public function auditLabel(): string
    {
        return $this->summary;
    }

    /**
     * Still somebody's to do.
     *
     * @param  Builder<RemoteHandsTask>  $query
     * @return Builder<RemoteHandsTask>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('state', [
            RemoteHandsState::Requested->value,
            RemoteHandsState::Scheduled->value,
            RemoteHandsState::InProgress->value,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => RemoteHandsState::class,
            'requested_at' => 'immutable_datetime',
            'scheduled_for' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
