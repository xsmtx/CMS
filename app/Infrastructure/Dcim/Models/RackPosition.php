<?php

declare(strict_types=1);

namespace App\Infrastructure\Dcim\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\RackPositionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What occupies which units of a rack (§11).
 *
 * **A machine this platform knows about, or a label for one it does not.** A
 * second table of devices would immediately be a second answer to "what
 * servers do we have" (ADR 0043); what this row adds is *where it is*, which
 * is the fact no other table holds. The label covers the switch, the patch
 * panel and the blanking plate, none of which this platform sells.
 *
 * The position is its lowest unit and a height, and units are numbered from
 * the bottom — so a 2U server at 10 occupies 10 and 11.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $rack_id
 * @property int $start_unit
 * @property int $unit_height
 * @property string|null $server_id
 * @property string|null $label
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class RackPosition extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<RackPositionFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'rack_id',
        'start_unit',
        'unit_height',
        'server_id',
        'label',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'unit_height' => 1,
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
     * Every unit this occupies, lowest first.
     *
     * @return list<int>
     */
    public function units(): array
    {
        return range($this->start_unit, $this->endUnit());
    }

    public function endUnit(): int
    {
        return $this->start_unit + max(1, $this->unit_height) - 1;
    }

    /**
     * What to call it on the elevation.
     *
     * The server's own name wins where there is one, because that is what an
     * operator searched for to get here; the label is for everything this
     * platform does not sell.
     */
    public function displayName(): string
    {
        $server = $this->server;

        if ($server !== null) {
            return $server->name;
        }

        return $this->label ?? '';
    }

    public function auditLabel(): string
    {
        return $this->displayName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_unit' => 'integer',
            'unit_height' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
