<?php

declare(strict_types=1);

namespace App\Infrastructure\Resources\Models;

use App\Domain\Infrastructure\Relation;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceEdgeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One relationship, and when it was true.
 *
 * **Append-only.** `ended_at` is the only column anything ever updates, which is
 * the same rule the ledger lives under (ADR 0024) and for the same reason: an
 * edge that was silently rewritten would take a piece of history with it, and
 * history is what §5 asks the graph for. A server moved to another rack closes
 * one edge and opens another.
 *
 * The organization is the **container's**. See ADR 0043: containment points
 * downward so that the boundary hides the container from the contained, and a
 * customer walking up from their service finds an edge they may not see.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $from_node_id
 * @property string $to_node_id
 * @property Relation $relation
 * @property string $source
 * @property array<string, mixed>|null $attributes
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ResourceEdge extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ResourceEdgeFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'resource_edges';

    protected $fillable = [
        'organization_id',
        'from_node_id',
        'to_node_id',
        'relation',
        'source',
        'attributes',
        'observed_at',
        'ended_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'source' => 'core',
    ];

    /**
     * @return BelongsTo<ResourceNode, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(ResourceNode::class, 'from_node_id');
    }

    /**
     * @return BelongsTo<ResourceNode, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(ResourceNode::class, 'to_node_id');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => Relation::class,
            'attributes' => 'array',
            'observed_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
