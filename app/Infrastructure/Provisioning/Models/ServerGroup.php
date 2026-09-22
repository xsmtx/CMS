<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Models;

use App\Domain\Provisioning\PlacementStrategy;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ServerGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pool of nodes, and the rule for picking one.
 *
 * Placement is a property of the group rather than of a product, so an
 * operator rebalancing their fleet changes one row instead of editing every
 * catalog entry that points at it.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property PlacementStrategy $placement_strategy
 * @property string|null $region
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ServerGroup extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ServerGroupFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'server_groups';

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'placement_strategy',
        'region',
        'notes',
    ];

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
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
            'placement_strategy' => PlacementStrategy::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
