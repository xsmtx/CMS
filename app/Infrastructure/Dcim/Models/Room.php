<?php

declare(strict_types=1);

namespace App\Infrastructure\Dcim\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room in a building (§11).
 *
 * A provider with one room makes one and stops thinking about it; the level
 * exists because the ones with four need it, not because everybody does.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $datacenter_id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Room extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'datacenter_id',
        'name',
    ];

    /**
     * @return BelongsTo<Datacenter, $this>
     */
    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    /**
     * @return HasMany<Rack, $this>
     */
    public function racks(): HasMany
    {
        return $this->hasMany(Rack::class);
    }

    /**
     * @return HasMany<RackRow, $this>
     */
    public function rackRows(): HasMany
    {
        return $this->hasMany(RackRow::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
