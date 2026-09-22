<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Models;

use App\Domain\Provisioning\ServerConnection;
use App\Domain\Provisioning\ServerStatus;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node services can be placed on.
 *
 * The secret is the credential for somebody's production control panel. It
 * is encrypted at rest, hidden from array conversion so an accidental
 * `toArray()` cannot put it in a response, and handed to an adapter only
 * inside a `ServerConnection` value object — which refuses to print it.
 *
 * @property string $id
 * @property string $name
 * @property string $module
 * @property string $hostname
 * @property string|null $ip_address
 * @property int $port
 * @property bool $secure
 * @property string $username
 * @property string|null $secret
 * @property ServerStatus $status
 * @property string|null $region
 * @property int $max_services
 * @property int $weight
 * @property string $health
 * @property string|null $health_message
 * @property CarbonImmutable|null $health_checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Server extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'servers';

    protected $fillable = [
        'organization_id',
        'server_group_id',
        'name',
        'module',
        'hostname',
        'ip_address',
        'port',
        'secure',
        'username',
        'secret',
        'status',
        'region',
        'max_services',
        'weight',
        'nameservers',
        'health',
        'health_message',
        'health_checked_at',
    ];

    protected $hidden = ['secret'];

    /**
     * @return BelongsTo<ServerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'server_group_id');
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Services that count against capacity.
     *
     * A terminated account is gone at the provider and does not occupy a
     * slot; a suspended one still does, because it is still there.
     *
     * @return HasMany<Service, $this>
     */
    public function occupyingServices(): HasMany
    {
        return $this->services()->whereNot('status', 'terminated');
    }

    public function hasCapacity(int $used): bool
    {
        // Zero means no limit, which is a real answer for a node an
        // operator is sizing by hand.
        return $this->max_services === 0 || $used < $this->max_services;
    }

    /**
     * What an adapter is allowed to know.
     */
    public function connection(): ServerConnection
    {
        return new ServerConnection(
            id: $this->id,
            hostname: $this->hostname,
            ipAddress: $this->ip_address,
            port: $this->port,
            username: $this->username,
            secret: $this->secret ?? '',
            secure: $this->secure,
            region: $this->region,
        );
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * Nodes that may be chosen right now.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopePlaceable(Builder $query): Builder
    {
        return $query->where('status', ServerStatus::Active->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted at rest. The key is the application key, so a
            // database dump on its own is not a fleet compromise.
            'secret' => 'encrypted',
            'status' => ServerStatus::class,
            'secure' => 'boolean',
            'port' => 'integer',
            'max_services' => 'integer',
            'weight' => 'integer',
            'health_checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
