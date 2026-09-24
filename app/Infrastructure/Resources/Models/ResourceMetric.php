<?php

declare(strict_types=1);

namespace App\Infrastructure\Resources\Models;

use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricUnit;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceMetricFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest reading of one thing about one node.
 *
 * One row per node and metric, upserted. This table is the *present*: the series
 * stays in Prometheus or Zabbix, where a series belongs (§14), and a chart asks
 * the adapter. What is here is what a screen renders, and it is bounded by the
 * number of nodes rather than by time.
 *
 * `metric` and `unit` are cast to enums, so a row written by an older version of
 * a module with a metric name this version does not know reads as null rather
 * than as a string nothing can render. That is the right failure: the Telemetry
 * screen can count unrecognised rows, and nothing downstream has to guess.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $resource_node_id
 * @property MetricKind|null $metric
 * @property MetricUnit|null $unit
 * @property float $value
 * @property CarbonImmutable $sampled_at
 * @property int|null $stale_after_seconds
 * @property string $source
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ResourceMetric extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ResourceMetricFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'resource_metrics';

    protected $fillable = [
        'organization_id',
        'resource_node_id',
        'metric',
        'unit',
        'value',
        'sampled_at',
        'stale_after_seconds',
        'source',
    ];

    /**
     * @return BelongsTo<ResourceNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(ResourceNode::class, 'resource_node_id');
    }

    /**
     * Whether this reading has outlived its usefulness.
     *
     * A reading with no declared freshness never goes stale, which is right for
     * one that came from a database rather than from a poller: an account count
     * does not go out of date, it changes.
     */
    public function isStale(?CarbonImmutable $now = null): bool
    {
        if ($this->stale_after_seconds === null) {
            return false;
        }

        $age = ($now ?? CarbonImmutable::now())->getTimestamp() - $this->sampled_at->getTimestamp();

        return $age > $this->stale_after_seconds;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => MetricKind::class,
            'unit' => MetricUnit::class,
            'value' => 'float',
            'sampled_at' => 'immutable_datetime',
            'stale_after_seconds' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
