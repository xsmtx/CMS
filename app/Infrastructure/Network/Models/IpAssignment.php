<?php

declare(strict_types=1);

namespace App\Infrastructure\Network\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\IpAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Who held an address, and when they stopped.
 *
 * Append-only: a release closes the row and nothing ever deletes one. §5 makes
 * historical ownership mandatory, and the reason is not tidiness — an abuse
 * report arrives weeks late and names an address and a date, and a platform
 * that overwrote the holder can only answer with whoever has it now.
 *
 * `holder_label` is copied at the moment of the assignment, for the same reason
 * an order line copies the catalog (ADR 0021): the service may be renamed or
 * terminated, and a history that cannot say who held the address is not a
 * history.
 *
 * The row belongs to the **seller**, whose address it is, and not to the
 * customer holding it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $ip_address_id
 * @property string $holder_type
 * @property string $holder_id
 * @property string $holder_label
 * @property string|null $actor_label
 * @property string|null $note
 * @property CarbonImmutable $assigned_at
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class IpAssignment extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<IpAssignmentFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'ip_assignments';

    protected $fillable = [
        'organization_id',
        'ip_address_id',
        'holder_type',
        'holder_id',
        'holder_label',
        'actor_label',
        'note',
        'assigned_at',
        'released_at',
    ];

    /**
     * @return BelongsTo<IpAddressRecord, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(IpAddressRecord::class, 'ip_address_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function holder(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return $this->released_at === null;
    }

    public function auditLabel(): string
    {
        return $this->holder_label;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
