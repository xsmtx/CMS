<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ApiRefreshTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link in a device's refresh chain (ADR 0049).
 *
 * Stored hashed, like an access token — the row keeps the *family*, never the
 * value, and `replaces_id` is what makes the chain readable afterwards.
 *
 * `used_at` is the whole mechanism. A token presented with it already filled
 * in is a reuse, which means either the thief or the owner is holding a stale
 * copy and there is no way to tell which; the device is revoked and both have
 * to sign in again. Detecting that and ignoring it would leave the thief with
 * a working session and the owner none the wiser, which is worse than not
 * rotating at all — because somebody would believe it was protecting them.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $api_device_id
 * @property string $token_hash
 * @property string|null $replaces_id
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ApiRefreshToken extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ApiRefreshTokenFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'api_refresh_tokens';

    protected $fillable = [
        'organization_id',
        'api_device_id',
        'token_hash',
        'replaces_id',
        'used_at',
        'expires_at',
    ];

    /**
     * @return BelongsTo<ApiDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(ApiDevice::class, 'api_device_id');
    }

    public function isSpent(): bool
    {
        return $this->used_at !== null;
    }

    public function hasExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expires_at->isBefore($now ?? CarbonImmutable::now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
