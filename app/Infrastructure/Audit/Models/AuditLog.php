<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit\Models;

use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Organizations\OrganizationBoundary;
use Carbon\CarbonImmutable;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only audit record.
 *
 * Updates and deletes are refused at the model layer. Retention pruning, when
 * an operator enables it, is performed by a dedicated maintenance command
 * that bypasses the model deliberately and is itself audited.
 *
 * Reads are bounded by organization. Installation-level events carry no
 * organization and are therefore visible only to an explicitly unscoped
 * query, which is what provider-wide tooling uses.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $action
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $target_label
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $metadata
 * @property string|null $reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $correlation_id
 * @property CarbonImmutable $occurred_at
 */
final class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $guarded = [];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeForCorrelation(Builder $query, string $correlationId): Builder
    {
        return $query->where('correlation_id', $correlationId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeAcrossOrganizations(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organization');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::addGlobalScope('organization', static function (Builder $query): void {
            app(OrganizationBoundary::class)->applyTo($query);
        });

        self::updating(static function (): never {
            throw new RuntimeException('Audit records are append-only and cannot be updated.');
        });

        self::deleting(static function (): never {
            throw new RuntimeException('Audit records are append-only and cannot be deleted.');
        });
    }
}
