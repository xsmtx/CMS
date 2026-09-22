<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Infrastructure\Organizations\OrganizationBoundary;
use Carbon\CarbonImmutable;
use Database\Factories\GatewayEventRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook we have already seen.
 *
 * Named `GatewayEventRecord` so it does not collide with the domain's
 * `GatewayEvent` value object, and because the two are different things:
 * one is what a provider said, the other is our note that we heard it.
 *
 * The unique index on (gateway, event_id) is what makes delivery-five-times
 * produce one payment. A check-then-write would race; the index cannot.
 *
 * Reads are bounded like everything else, but rows are not stamped on
 * write: a webhook arrives before anyone knows which organization it
 * concerns, and refusing to record it until we do would lose the evidence
 * of the events we could not match.
 *
 * @property string $event_id
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable $received_at
 */
final class GatewayEventRecord extends Model
{
    /** @use HasFactory<GatewayEventRecordFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'gateway_events';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::addGlobalScope('organization', static function (Builder $query): void {
            app(OrganizationBoundary::class)->applyTo($query);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
