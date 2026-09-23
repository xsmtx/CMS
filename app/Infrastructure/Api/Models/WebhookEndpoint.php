<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Models;

use App\Domain\Api\WebhookEvent;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a customer or an operator wants this platform's events posted.
 *
 * The secret is encrypted at rest and never returned by the API after
 * creation — it is shown once, like a token, because it is one. Anybody who
 * can read it can forge an event from us.
 *
 * `events` empty means *all of them*, which is the right default for a
 * first integration: an endpoint that silently receives nothing because
 * nobody ticked a box is a worse first experience than one that receives
 * too much.
 *
 * @property string $url
 * @property string|null $description
 * @property string $secret
 * @property list<string>|null $events
 * @property bool $is_active
 * @property CarbonImmutable|null $last_delivered_at
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $disabled_at
 */
final class WebhookEndpoint extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'webhook_endpoints';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'url',
        'description',
        'secret',
        'events',
        'is_active',
        'last_delivered_at',
        'consecutive_failures',
        'disabled_at',
    ];

    /**
     * The secret is a credential. Hidden so that an accidental `toArray()`
     * in a resource or a log line cannot publish it.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /** @var array<string, bool|int> */
    protected $attributes = [
        'is_active' => true,
        'consecutive_failures' => 0,
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function wants(WebhookEvent $event): bool
    {
        $events = $this->events;

        // Empty means everything, which is what an integration that has
        // not thought about it yet actually wants.
        return $events === null || $events === [] || in_array($event->value, $events, true);
    }

    public function auditLabel(): string
    {
        return $this->description ?? $this->url;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('disabled_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_delivered_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
