<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Models;

use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Organizations\OrganizationBoundary;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One message, on one channel, to one person.
 *
 * Append-only, and everything on it is a copy: a log that joined to
 * `contacts` would stop being readable the moment somebody was deleted or
 * corrected their address, and "which address did we actually send it to"
 * is the question that matters.
 *
 * @property NotificationEvent $event
 * @property NotificationChannel $channel
 * @property DeliveryStatus $status
 * @property string|null $recipient_name
 * @property string|null $recipient_address
 * @property string|null $rendered_subject
 * @property string|null $error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $delivered_at
 */
final class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'notification_deliveries';

    protected $fillable = [
        'organization_id',
        'event',
        'channel',
        'status',
        'recipient_name',
        'recipient_address',
        'subject_type',
        'subject_id',
        'locale',
        'rendered_subject',
        'reference',
        'error',
        'correlation_id',
        'created_at',
        'delivered_at',
    ];

    /**
     * A delivery row belongs to the platform as much as to an
     * organization: a message to a staff member has no customer behind it.
     * The named scope is registered by hand, the way `AuditLog` does it.
     */
    protected static function booted(): void
    {
        self::addGlobalScope('organization', static function (Builder $query): void {
            app(OrganizationBoundary::class)->applyToNullable($query);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'channel' => NotificationChannel::class,
            'status' => DeliveryStatus::class,
            'created_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
