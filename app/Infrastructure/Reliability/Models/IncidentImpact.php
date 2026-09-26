<?php

declare(strict_types=1);

namespace App\Infrastructure\Reliability\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the incident was worth, as it was at the moment it ended.
 *
 * **Frozen, and that is the whole reason this is a table rather than a
 * method.** `ImpactSummary` reads the graph and the graph moves — a server is
 * decommissioned, a customer leaves, a service is renamed — so an impact
 * recomputed in March is not the impact anybody acted on in January. The same
 * reasoning that froze an issued invoice (ADR 0023), applied to a figure
 * somebody will quote in a credit conversation.
 *
 * `recurring` is money by currency, as a list. There is no rate anywhere in
 * this product, so a total across currencies is a figure that means nothing
 * and is exactly the one somebody would quote.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $incident_id
 * @property int $services
 * @property int $customers
 * @property list<array{currency: string, amount: string, minor: int}> $recurring
 * @property CarbonImmutable $frozen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class IncidentImpact extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'incident_id',
        'services',
        'customers',
        'recurring',
        'frozen_at',
    ];

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'services' => 'integer',
            'customers' => 'integer',
            'recurring' => 'array',
            'frozen_at' => 'immutable_datetime',
        ];
    }
}
