<?php

declare(strict_types=1);

namespace App\Application\Api\Devices;

use App\Domain\Api\DevicePlatform;
use App\Domain\Api\DeviceSession;
use App\Domain\Api\Exceptions\SessionRefused;
use App\Infrastructure\Api\Models\ApiDevice;
use App\Infrastructure\Api\Models\ApiRefreshToken;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Opening, rotating and ending a device session (ADR 0049).
 *
 * One class, because the three acts share one invariant: **a device holds at
 * most one live refresh token, and every access token it ever held dies with
 * it.** Splitting them would put that invariant in three places, and the
 * first time the three disagreed a revoked phone would keep working for
 * another fourteen minutes.
 *
 * Nothing here decides *who* may open a session. Authentication happens
 * before this is called, in the place that already knows how to check a
 * password and a second factor.
 */
final readonly class DeviceSessions
{
    public function __construct(private DeviceOwners $owners) {}

    /**
     * Open a session, and hand back the only copy of both tokens.
     *
     * @param  list<string>  $scopes
     */
    public function open(
        Model $owner,
        string $name,
        DevicePlatform $platform,
        array $scopes,
        bool $staff = false,
    ): DeviceSession {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($owner, $name, $platform, $scopes, $staff, $now): DeviceSession {
            $device = ApiDevice::query()->create([
                'organization_id' => $this->owners->organizationOf($owner),
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'name' => $name,
                'platform' => $platform,
                'last_seen_at' => $now,
            ]);

            Audit::action('identity.device.opened')
                ->by($owner)
                ->on($device)
                ->withMetadata([
                    'platform' => $platform->value,
                    // What it may do is the first question after an incident,
                    // and it belongs in the record rather than in a row
                    // somebody might revoke.
                    'scopes' => implode(' ', $scopes),
                ])
                ->write();

            return $this->issue($device, $owner, $scopes, $staff, $now);
        });
    }

    /**
     * Exchange a refresh token for a new pair.
     *
     * Every refusal is a `SessionRefused`, and two of them — a reuse and an
     * account that can no longer sign in — also **end the device**.
     *
     * That revocation deliberately happens **outside** the transaction the
     * refusal rolls back, and it was written the other way round first: a
     * revoke inside the transaction is undone by the exception that follows
     * it, so a detected reuse revoked nothing at all and the thief kept
     * working. The whole mechanism was silently absent and every other test
     * in the file passed.
     */
    public function rotate(string $presented, bool $staff = false): DeviceSession
    {
        try {
            return DB::transaction(fn (): DeviceSession => $this->exchange($presented, $staff));
        } catch (SessionRefused $refused) {
            $device = $refused->deviceId() === null
                ? null
                : ApiDevice::query()->find($refused->deviceId());

            if ($device instanceof ApiDevice) {
                $this->revoke($device, reason: $refused->reason());
            }

            throw $refused;
        }
    }

    /**
     * End a device, and everything it holds, in one act.
     */
    public function revoke(ApiDevice $device, string $reason, ?Model $by = null): void
    {
        if (! $device->isLive()) {
            return;
        }

        DB::transaction(function () use ($device, $reason, $by): void {
            $device->forceFill([
                'revoked_at' => CarbonImmutable::now(),
                'revoked_reason' => $reason,
            ])->save();

            // The refresh family and the outstanding access tokens together.
            // A revocation that left one alive is a revocation somebody
            // trusts and should not.
            $device->refreshTokens()->delete();
            $this->killAccessTokens($device);

            $entry = Audit::action('identity.device.revoked')
                ->on($device)
                ->withMetadata(['reason' => $reason]);

            // An expiry or a detected reuse has no actor, and a record whose
            // author was invented would be a record that lied about who
            // acted — the rule `AccessGrants::revoke()` needed.
            $by instanceof Model
                ? $entry->by($by)->write()
                : $entry->bySystem('device:'.$reason)->write();
        });
    }

    private function exchange(string $presented, bool $staff): DeviceSession
    {
        $now = CarbonImmutable::now();

        /*
         * `lockForUpdate` because two requests presenting the same token at
         * once is exactly the race this mechanism exists to notice: without
         * it, both would read `used_at` as null and both would succeed,
         * which is the reuse going undetected.
         */
        $token = ApiRefreshToken::query()
            ->where('token_hash', $this->hash($presented))
            ->lockForUpdate()
            ->first();

        if (! $token instanceof ApiRefreshToken) {
            throw SessionRefused::unknown();
        }

        $device = $token->device()->first();

        if (! $device instanceof ApiDevice || ! $device->isLive()) {
            throw SessionRefused::deviceRevoked();
        }

        if ($token->isSpent()) {
            /*
             * Either the thief or the owner is holding a stale copy, and
             * there is no way to tell which — so both lose the session.
             * Rotation that noticed this and carried on would leave the thief
             * working and the owner none the wiser, which is worse than not
             * rotating, because somebody would believe in it.
             */
            throw SessionRefused::reused($device->id);
        }

        if ($token->hasExpired($now)) {
            throw SessionRefused::expired();
        }

        $owner = $device->owner()->first();

        if (! $owner instanceof Model || ! $this->owners->maySignIn($owner)) {
            // Revoking an account revokes its devices; this is that rule,
            // caught at the moment somebody tries to use one.
            throw SessionRefused::ownerUnavailable($device->id);
        }

        $token->forceFill(['used_at' => $now])->save();

        $scopes = $this->scopesOf($device);

        // Everything the device was holding stops working now rather than
        // when it happens to expire: a rotation is a new session, and two
        // live access tokens for one device is one more than exists.
        $this->killAccessTokens($device);

        $device->forceFill(['last_seen_at' => $now])->save();

        return $this->issue($device, $owner, $scopes, $staff, $now, replaces: $token->id);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function issue(
        ApiDevice $device,
        Model $owner,
        array $scopes,
        bool $staff,
        CarbonImmutable $now,
        ?string $replaces = null,
    ): DeviceSession {
        $accessExpires = $now->addMinutes((int) config('platform.api.devices.access_minutes', 15));

        $refreshExpires = $now->addDays((int) config(
            $staff ? 'platform.api.devices.staff_refresh_days' : 'platform.api.devices.refresh_days',
            $staff ? 7 : 30,
        ));

        $access = $this->owners->createToken($owner, $device->name, $scopes, $accessExpires);

        $access->accessToken->forceFill(['api_device_id' => $device->id])->save();

        $refresh = Str::random(64);

        ApiRefreshToken::query()->create([
            'organization_id' => $device->organization_id,
            'api_device_id' => $device->id,
            'token_hash' => $this->hash($refresh),
            'replaces_id' => $replaces,
            'expires_at' => $refreshExpires,
        ]);

        return new DeviceSession(
            deviceId: $device->id,
            accessToken: $access->plainTextToken,
            accessExpiresAt: $accessExpires,
            refreshToken: $refresh,
            refreshExpiresAt: $refreshExpires,
        );
    }

    /**
     * What the device was allowed to do, read from the token it last held.
     *
     * A rotation never widens: the new pair carries exactly what the old one
     * did. Taking the scopes from the request instead would make the refresh
     * endpoint a way to grant oneself more than was consented to.
     *
     * @return list<string>
     */
    private function scopesOf(ApiDevice $device): array
    {
        $token = PersonalAccessToken::query()
            ->where('api_device_id', $device->id)
            ->latest('id')
            ->first();

        if (! $token instanceof PersonalAccessToken) {
            return [];
        }

        /** @var list<string> $abilities */
        $abilities = is_array($token->abilities) ? array_values($token->abilities) : [];

        return $abilities;
    }

    private function killAccessTokens(ApiDevice $device): void
    {
        PersonalAccessToken::query()->where('api_device_id', $device->id)->delete();
    }

    /**
     * SHA-256, which is what Sanctum hashes an access token with.
     *
     * No salt and no stretching, deliberately: this is a 64-character random
     * string rather than a password, so there is nothing to guess and a slow
     * hash would only make the refresh endpoint expensive to call.
     */
    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
