<?php

declare(strict_types=1);

namespace App\Infrastructure\Resources\Models;

use App\Domain\Health\HealthState;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceNodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing in the graph.
 *
 * The namespace is `Resources` rather than `Infrastructure`, which would have
 * read `App\Infrastructure\Infrastructure`. The domain context is
 * `App\Domain\Infrastructure`; the tables are `resource_*`; this is the
 * Eloquent side of them and is named after the tables.
 *
 * Deliberately thin. A node knows what kind of thing it is, what the source
 * calls it, and which row it stands for; it does not know a service's price or a
 * server's hostname, because those are on the service and the server (ADR 0043).
 * `label` is a cache for drawing a tree in one query and nothing reads it to make
 * a decision.
 *
 * `health` is the one derived column, written by the telemetry normalizer. It is
 * a string rather than a cast enum because `unknown` is a fourth state that
 * `HealthState` does not have and should not: the installation's own health check
 * can never be "nobody has told us" — something always answers — and a node's
 * can, which is the entire reason the Telemetry screen exists.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $kind
 * @property string $node_key
 * @property string $label
 * @property string $source
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $health
 * @property string|null $health_message
 * @property array<string, mixed>|null $attributes
 * @property CarbonImmutable|null $discovered_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $retired_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ResourceNode extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ResourceNodeFactory> */
    use HasFactory;

    use HasUlids;

    public const string HealthUnknown = 'unknown';

    protected $table = 'resource_nodes';

    protected $fillable = [
        'organization_id',
        'kind',
        'node_key',
        'label',
        'source',
        'subject_type',
        'subject_id',
        'health',
        'health_message',
        'attributes',
        'discovered_at',
        'last_seen_at',
        'retired_at',
    ];

    /**
     * The database defaults, declared again.
     *
     * A default fills the row and leaves the model in memory without the
     * attribute, and a cast reads that absence as null. It bit the money columns
     * in Phase 4 and the booleans in Phase 7, and `health` would be the third
     * time: a freshly created node would render a blank badge until something
     * refreshed it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => 'core',
        'health' => self::HealthUnknown,
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ResourceEdge, $this>
     */
    public function outgoing(): HasMany
    {
        return $this->hasMany(ResourceEdge::class, 'from_node_id');
    }

    /**
     * @return HasMany<ResourceEdge, $this>
     */
    public function incoming(): HasMany
    {
        return $this->hasMany(ResourceEdge::class, 'to_node_id');
    }

    /**
     * @return HasMany<ResourceMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(ResourceMetric::class, 'resource_node_id');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * The health state, or null when nothing has ever said.
     *
     * Null rather than `Ok`, because "nobody is watching this" and "this is
     * fine" are different answers and a screen that shows the second for the
     * first is a screen that lies quietly.
     */
    public function healthState(): ?HealthState
    {
        return HealthState::tryFrom($this->health);
    }

    public function auditLabel(): string
    {
        return $this->label;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'discovered_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
