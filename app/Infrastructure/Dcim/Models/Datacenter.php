<?php

declare(strict_types=1);

namespace App\Infrastructure\Dcim\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\DatacenterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A building, as somebody's own paperwork names it (§11).
 *
 * No adapter discovers this. Core ships no naming convention and no
 * assumption that a datacenter has more than one room.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $code
 * @property string|null $address
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Datacenter extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<DatacenterFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'address',
        'note',
    ];

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
