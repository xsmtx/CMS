<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Models;

use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Organizations\OrganizationBoundary;
use Carbon\CarbonImmutable;
use Database\Factories\InAppNotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A message waiting in the interface.
 *
 * Addressed to a contact or a staff member by morph, because both sides get
 * them and neither is the other's subclass.
 *
 * @property string $title
 * @property string $body
 * @property string|null $action_url
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
final class InAppNotification extends Model
{
    /** @use HasFactory<InAppNotificationFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'in_app_notifications';

    protected $fillable = [
        'organization_id',
        'notifiable_type',
        'notifiable_id',
        'event',
        'title',
        'body',
        'action_url',
        'read_at',
        'created_at',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Bounded like the delivery log: by the organization when the row has
     * one, and visible to the platform when it does not. A message to a
     * staff member has no customer behind it.
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
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
