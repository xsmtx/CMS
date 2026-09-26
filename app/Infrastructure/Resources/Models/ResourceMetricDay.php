<?php

declare(strict_types=1);

namespace App\Infrastructure\Resources\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceMetricDayFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one metric did on one day, for one resource.
 *
 * The daily series capacity questions are answered from. `average()` divides
 * rather than storing: a running average that has lost its denominator is a
 * number that drifts, and the denominator is the sample count anyway.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $resource_node_id
 * @property string $metric
 * @property string $unit
 * @property CarbonImmutable $day
 * @property int $samples
 * @property float $minimum
 * @property float $maximum
 * @property float $sum
 * @property float $last
 */
final class ResourceMetricDay extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ResourceMetricDayFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'resource_metric_days';

    protected $fillable = [
        'organization_id',
        'resource_node_id',
        'metric',
        'unit',
        'day',
        'samples',
        'minimum',
        'maximum',
        'sum',
        'last',
    ];

    /**
     * Zeroes in `$attributes` as well as in the column defaults: a default
     * fills the row and leaves the model in memory without the attribute,
     * and a cast then reads that absence as null. It bit the money columns in
     * Phase 4 and the booleans in Phase 7.
     *
     * @var array<string, int>
     */
    protected $attributes = ['samples' => 0];

    /**
     * @return BelongsTo<ResourceNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(ResourceNode::class, 'resource_node_id');
    }

    public function average(): float
    {
        return $this->samples > 0 ? $this->sum / $this->samples : 0.0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'samples' => 'integer',
            'minimum' => 'float',
            'maximum' => 'float',
            'sum' => 'float',
            'last' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
