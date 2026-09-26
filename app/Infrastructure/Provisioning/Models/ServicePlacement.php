<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Models;

use App\Domain\Provisioning\PlacementFactor;
use App\Domain\Provisioning\PlacementStrategy;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ServicePlacementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one placement decision.
 *
 * Append-only: a service moved to another node gets a second row, and the answer
 * to "where has this lived" is the list of them.
 *
 * `server_name` is copied at the moment of the decision, for the same reason an
 * order line copies the catalog (ADR 0021): the node may be renamed or
 * decommissioned, and a record of a decision that cannot say what was chosen is
 * not a record.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $service_id
 * @property string|null $server_id
 * @property PlacementStrategy $strategy
 * @property string $server_name
 * @property float|null $score
 * @property int $candidates
 * @property array<string, array{score: float, weight: int, measure: float|null, assumed?: bool}>|null $factors
 * @property CarbonImmutable $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ServicePlacement extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ServicePlacementFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'service_placements';

    protected $fillable = [
        'organization_id',
        'service_id',
        'server_id',
        'strategy',
        'server_name',
        'score',
        'candidates',
        'factors',
        'decided_at',
    ];

    /**
     * The column default declared again, because a default fills the row and
     * leaves the model in memory without the attribute.
     *
     * @var array<string, int>
     */
    protected $attributes = ['candidates' => 0];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * The factors that were read, worst score first.
     *
     * A placement is explained by its objections rather than by the things that
     * were fine, so this is the order a screen wants.
     *
     * @return list<array{factor: PlacementFactor, score: float, weight: int, measure: float|null, assumed: bool}>
     */
    public function readings(): array
    {
        $readings = [];

        foreach ($this->factors ?? [] as $slug => $reading) {
            $factor = PlacementFactor::tryFrom($slug);

            // A slug this version does not know is skipped rather than shown
            // raw: a factor a module added, or one a later release renamed.
            if (! $factor instanceof PlacementFactor) {
                continue;
            }

            $readings[] = [
                'factor' => $factor,
                'score' => (float) $reading['score'],
                'weight' => (int) $reading['weight'],
                'measure' => isset($reading['measure']) ? (float) $reading['measure'] : null,
                // Absent rather than false in a row written before the flag
                // existed: what a factor holds is free-form JSON, and reading a
                // missing key as "this was measured" would be the worse way
                // round of the two.
                'assumed' => (bool) ($reading['assumed'] ?? false),
            ];
        }

        usort($readings, static fn (array $a, array $b): int => [$a['score'], $a['factor']->value] <=> [$b['score'], $b['factor']->value]);

        return $readings;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => PlacementStrategy::class,
            'score' => 'float',
            'candidates' => 'integer',
            'factors' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
