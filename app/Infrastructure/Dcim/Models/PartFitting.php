<?php

declare(strict_types=1);

namespace App\Infrastructure\Dcim\Models;

use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\PartFittingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part, in one machine, between two moments (§11).
 *
 * **Append-only.** Taking a part out closes the fitting; putting it somewhere
 * else opens a new one. `ip_assignments` made the same choice for the same
 * reason: the question is always historical, and a row that could be
 * rewritten is a history nobody can rely on.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $hardware_part_id
 * @property string $server_id
 * @property CarbonImmutable $fitted_at
 * @property CarbonImmutable|null $removed_at
 * @property string|null $fitted_by
 * @property string|null $removed_by
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PartFitting extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<PartFittingFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'organization_id',
        'hardware_part_id',
        'server_id',
        'fitted_at',
        'removed_at',
        'fitted_by',
        'removed_by',
        'note',
    ];

    /**
     * @return BelongsTo<HardwarePart, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(HardwarePart::class, 'hardware_part_id');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function fittedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'fitted_by');
    }

    public function isOpen(): bool
    {
        return $this->removed_at === null;
    }

    public function auditLabel(): string
    {
        return $this->part?->displayName() ?? $this->hardware_part_id;
    }

    /**
     * Still in the machine.
     *
     * @param  Builder<PartFitting>  $query
     * @return Builder<PartFitting>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fitted_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
