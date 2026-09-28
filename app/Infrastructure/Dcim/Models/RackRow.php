<?php

declare(strict_types=1);

namespace App\Infrastructure\Dcim\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\RackRowFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row of racks in a room (§11).
 *
 * `rack_rows` rather than `rows`, because `rows` is a reserved word in enough
 * SQL dialects to be a trap somebody trips over during a migration to one.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $room_id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class RackRow extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<RackRowFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'rack_rows';

    protected $fillable = [
        'organization_id',
        'room_id',
        'name',
    ];

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<Rack, $this>
     */
    public function racks(): HasMany
    {
        return $this->hasMany(Rack::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
