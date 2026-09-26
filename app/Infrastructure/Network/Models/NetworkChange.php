<?php

declare(strict_types=1);

namespace App\Infrastructure\Network\Models;

use App\Domain\Network\NetworkChangeState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Operations\Models\Operation;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\NetworkChangeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requested change to a device's configuration.
 *
 * The record §6 asks for, and the state machine that decides what may happen
 * to it next. Nothing here talks to a device: `ApplyNetworkChange` does that,
 * from a job, through an adapter, and only from a row that has already been
 * approved and backed up.
 *
 * `requires_approval` is stated on the row rather than read from config at
 * decision time, for the same reason an issued invoice copies its bill-to
 * party (ADR 0023): the rule that applied when somebody asked is the rule that
 * governs the request, and an installation that relaxed the setting overnight
 * must not thereby have approved yesterday's changes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $resource_node_id
 * @property NetworkChangeState $state
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property string $summary
 * @property string $reason
 * @property string|null $ticket
 * @property string|null $decision_note
 * @property bool $requires_approval
 * @property string $intended
 * @property string|null $baseline
 * @property string|null $diff
 * @property string|null $fingerprint_before
 * @property string|null $backup
 * @property CarbonImmutable|null $backed_up_at
 * @property string|null $result
 * @property string|null $operation_id
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class NetworkChange extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<NetworkChangeFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'resource_node_id',
        'state',
        'requested_by',
        'decided_by',
        'summary',
        'reason',
        'ticket',
        'decision_note',
        'requires_approval',
        'intended',
        'baseline',
        'diff',
        'fingerprint_before',
        'backup',
        'backed_up_at',
        'result',
        'operation_id',
        'decided_at',
        'applied_at',
    ];

    /**
     * The database defaults, declared here too.
     *
     * A column default fills the row and leaves the model in memory without
     * the attribute, and the cast then reads that absence as null — which for
     * `requires_approval` would mean a change created in code appeared not to
     * need approving. It bit the money columns in Phase 4 and the booleans in
     * Phase 7.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => 'requested',
        'requires_approval' => true,
    ];

    /**
     * @return BelongsTo<ResourceNode, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(ResourceNode::class, 'resource_node_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'requested_by');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'decided_by');
    }

    /**
     * @return BelongsTo<Operation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function auditLabel(): string
    {
        return $this->summary;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => NetworkChangeState::class,
            'requires_approval' => 'boolean',
            'backed_up_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }
}
