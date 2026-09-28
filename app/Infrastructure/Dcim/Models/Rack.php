<?php

declare(strict_types=1);

namespace App\Infrastructure\Dcim\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\RackFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cabinet, and how much of it is free (§11).
 *
 * Units are numbered from the bottom, which is how every rack in the world is
 * labelled and how everybody reads one. Forty-two because that is what a rack
 * usually is; core states no other number and an operator changes it.
 *
 * `power_capacity_watts` is nullable, and the null is "nobody told us" rather
 * than zero: a provider renting space often is not told, and a zero would
 * draw every rack as over capacity.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $room_id
 * @property string|null $rack_row_id
 * @property string $name
 * @property int $units
 * @property int|null $power_capacity_watts
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Rack extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<RackFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'room_id',
        'rack_row_id',
        'name',
        'units',
        'power_capacity_watts',
        'note',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'units' => 42,
    ];

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<RackRow, $this>
     */
    public function rackRow(): BelongsTo
    {
        return $this->belongsTo(RackRow::class);
    }

    /**
     * @return HasMany<RackPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(RackPosition::class);
    }

    /**
     * Which units are taken, from the positions already loaded.
     *
     * Reads the relation rather than querying, so a screen drawing forty
     * racks does it in one query — the shape `LazyLoadingTest` exists to keep
     * honest.
     *
     * @return list<int>
     */
    public function occupiedUnits(): array
    {
        $units = [];

        foreach ($this->positions as $position) {
            foreach ($position->units() as $unit) {
                $units[$unit] = true;
            }
        }

        $taken = array_keys($units);
        sort($taken);

        return $taken;
    }

    public function freeUnits(): int
    {
        return max(0, $this->units - count($this->occupiedUnits()));
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'power_capacity_watts' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
