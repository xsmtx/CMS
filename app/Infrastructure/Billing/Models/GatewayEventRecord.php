<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\GatewayEventRecordFactory;
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
 * @property string $event_id
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable $received_at
 */
final class GatewayEventRecord extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<GatewayEventRecordFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'gateway_events';

    protected $guarded = [];

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
