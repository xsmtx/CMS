<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Models;

use App\Domain\Api\DeliveryState;
use App\Domain\Api\WebhookEvent;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to post one event to one endpoint.
 *
 * `event_id` is stable across every attempt and every manual redelivery, so
 * a receiver can deduplicate on it — the same promise this platform asks of
 * its own clients with an idempotency key, made in the other direction.
 *
 * The payload is stored rather than rebuilt at redelivery time. An event is
 * a statement about a moment, and re-rendering it a week later from current
 * rows would post a different fact under the same event id.
 *
 * @property string $event_id
 * @property WebhookEvent $event
 * @property array<string, mixed> $payload
 * @property DeliveryState $status
 * @property int $attempt
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $error
 * @property int|null $duration_ms
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 */
final class WebhookDelivery extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'organization_id',
        'endpoint_id',
        'event_id',
        'event',
        'payload',
        'status',
        'attempt',
        'response_status',
        'response_body',
        'error',
        'duration_ms',
        'next_attempt_at',
        'delivered_at',
        'created_at',
    ];

    /** @var array<string, int|string> */
    protected $attributes = [
        'status' => 'pending',
        'attempt' => 0,
    ];

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeDueForRetry(Builder $query): Builder
    {
        return $query->where('status', DeliveryState::Retrying->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', CarbonImmutable::now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => WebhookEvent::class,
            'status' => DeliveryState::class,
            'payload' => 'array',
            'attempt' => 'integer',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
