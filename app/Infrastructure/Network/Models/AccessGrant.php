<?php

declare(strict_types=1);

namespace App\Infrastructure\Network\Models;

use App\Domain\Network\GrantableCapability;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\AccessGrantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody may do one more thing than usual, until a time.
 *
 * **Whether it is live is a question about two timestamps**, never a column.
 * `scopeLive()` is the one place that asks it, so the gate and the screen
 * cannot disagree — and nothing depends on the expiry sweep having run on
 * time, which is ADR 0031 applied to a permission rather than to an invoice.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $staff_user_id
 * @property string|null $granted_by
 * @property string|null $revoked_by
 * @property GrantableCapability $capability
 * @property string|null $resource_node_id
 * @property string $reason
 * @property string|null $ticket
 * @property string|null $revocation_reason
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $network_change_id
 * @property string|null $removal_change_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AccessGrant extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<AccessGrantFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'staff_user_id',
        'granted_by',
        'revoked_by',
        'capability',
        'resource_node_id',
        'reason',
        'ticket',
        'revocation_reason',
        'expires_at',
        'revoked_at',
        'network_change_id',
        'removal_change_id',
    ];

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'staff_user_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'granted_by');
    }

    /**
     * @return BelongsTo<ResourceNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(ResourceNode::class, 'resource_node_id');
    }

    public function auditLabel(): string
    {
        return $this->capability->value;
    }

    /**
     * Not revoked, and not yet past its expiry.
     *
     * @param  Builder<AccessGrant>  $query
     * @return Builder<AccessGrant>
     */
    protected function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', CarbonImmutable::now());
    }

    /**
     * Past its expiry and nobody has written that down yet.
     *
     * What the sweep asks about — a question about rows rather than about the
     * clock, so a scheduler that was down for three hours catches up.
     *
     * @param  Builder<AccessGrant>  $query
     * @return Builder<AccessGrant>
     */
    protected function scopeLapsed(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '<=', CarbonImmutable::now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capability' => GrantableCapability::class,
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
