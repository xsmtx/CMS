<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Models;

use App\Domain\Api\DevicePlatform;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ApiDeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A phone, a tablet or a browser somebody opened a session from (ADR 0049).
 *
 * The thing a refresh family hangs off, and the thing somebody revokes when
 * a device is lost. Revoking is one act that ends everything the device holds
 * — its refresh family *and* its outstanding access tokens — because a
 * revocation that left a valid access token alive for another fourteen
 * minutes is a revocation somebody trusts and should not.
 *
 * A revoked device is kept rather than deleted, with when and why.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $name
 * @property DevicePlatform $platform
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ApiDevice extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ApiDeviceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'api_devices';

    protected $fillable = [
        'organization_id',
        'owner_type',
        'owner_id',
        'name',
        'platform',
        'last_seen_at',
        'revoked_at',
        'revoked_reason',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'platform' => DevicePlatform::Other->value,
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ApiRefreshToken, $this>
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(ApiRefreshToken::class);
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
