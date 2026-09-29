<?php

declare(strict_types=1);

use App\Application\Api\Devices\DeviceSessions;
use App\Domain\Api\DevicePlatform;
use App\Domain\Api\Exceptions\SessionRefused;
use App\Domain\Identity\AccountStatus;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Api\Models\ApiDevice;
use App\Infrastructure\Api\Models\ApiRefreshToken;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Device sessions: short access, long refresh, rotated (ADR 0049).
 *
 * **Rotation without reuse detection is theatre**, and most of this file is
 * about the detection rather than the rotation. A refresh token presented
 * twice means either the thief or the owner is holding a stale copy and there
 * is no way to tell which — so the device goes, and both sign in again.
 *
 * The other rule with a test of its own is that revoking a device ends
 * everything it holds **now**, not when its access token happens to expire.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->contact = Contact::factory()->create(['portal_access' => true]);
    $this->sessions = app(DeviceSessions::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('hands back both tokens exactly once, and stores neither', function (): void {
    $session = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, ['profile:read']);

    expect($session->accessToken)->not->toBe('')
        ->and($session->refreshToken)->not->toBe('');

    $stored = ApiRefreshToken::query()->firstOrFail();

    // The row keeps the family, never the value.
    expect($stored->token_hash)->toBe(hash('sha256', $session->refreshToken))
        ->and($stored->getAttributes())->not->toHaveKey('token');
});

it('gives the access token minutes and the refresh token days', function (): void {
    CarbonImmutable::setTestNow('2026-09-29 12:00:00');

    $session = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, []);

    expect($session->accessExpiresAt->toDateTimeString())->toBe('2026-09-29 12:15:00')
        ->and($session->refreshExpiresAt->toDateTimeString())->toBe('2026-10-29 12:00:00');
});

it('caps a staff device harder than a customer one', function (): void {
    CarbonImmutable::setTestNow('2026-09-29 12:00:00');

    $staff = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $session = $this->sessions->open($staff, 'Pixel', DevicePlatform::Android, [], staff: true);

    // The customer app reads invoices; the staff app approves changes.
    expect($session->refreshExpiresAt->toDateTimeString())->toBe('2026-10-06 12:00:00');
});

it('rotates a refresh token into a new pair', function (): void {
    $first = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, ['profile:read']);
    $second = $this->sessions->rotate($first->refreshToken);

    expect($second->refreshToken)->not->toBe($first->refreshToken)
        ->and($second->accessToken)->not->toBe($first->accessToken)
        ->and($second->deviceId)->toBe($first->deviceId);

    // The chain is readable afterwards, which is what makes reuse detectable.
    $new = ApiRefreshToken::query()->where('token_hash', hash('sha256', $second->refreshToken))->firstOrFail();
    $old = ApiRefreshToken::query()->where('token_hash', hash('sha256', $first->refreshToken))->firstOrFail();

    expect($new->replaces_id)->toBe($old->id)
        ->and($old->used_at)->not->toBeNull();
});

/**
 * The one that matters.
 */
it('revokes the whole device when a spent refresh token comes back', function (): void {
    $first = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, ['profile:read']);
    $second = $this->sessions->rotate($first->refreshToken);

    expect(fn () => $this->sessions->rotate($first->refreshToken))
        ->toThrow(SessionRefused::class);

    $device = ApiDevice::query()->findOrFail($first->deviceId);

    expect($device->isLive())->toBeFalse()
        ->and($device->revoked_reason)->toBe('reused')
        // Both parties lose it: the honest copy stops working too, because
        // there is no way to tell the thief from the owner.
        ->and(ApiRefreshToken::query()->where('api_device_id', $device->id)->count())->toBe(0);

    expect(fn () => $this->sessions->rotate($second->refreshToken))
        ->toThrow(SessionRefused::class);
});

it('names each refusal separately, and never tells the caller which', function (): void {
    expect(fn () => $this->sessions->rotate('nothing-like-a-token'))
        ->toThrow(function (SessionRefused $e): void {
            expect($e->reason())->toBe('unknown');
        });

    $expired = $this->sessions->open($this->contact, 'Old', DevicePlatform::Android, []);

    ApiRefreshToken::query()
        ->where('token_hash', hash('sha256', $expired->refreshToken))
        ->update(['expires_at' => CarbonImmutable::now()->subDay()]);

    expect(fn () => $this->sessions->rotate($expired->refreshToken))
        ->toThrow(function (SessionRefused $e): void {
            expect($e->reason())->toBe('expired');
        });
});

it('ends every access token the device held when it rotates', function (): void {
    $first = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, ['profile:read']);
    $this->sessions->rotate($first->refreshToken);

    // Two live access tokens for one device is one more than exists.
    expect(PersonalAccessToken::query()->where('api_device_id', $first->deviceId)->count())->toBe(1);
});

it('never widens what a device may do', function (): void {
    $first = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, ['profile:read']);
    $second = $this->sessions->rotate($first->refreshToken);

    $token = PersonalAccessToken::query()->where('api_device_id', $second->deviceId)->firstOrFail();

    // The refresh endpoint is not a way to grant oneself more than was
    // consented to.
    expect($token->abilities)->toBe(['profile:read']);
});

it('ends everything the device holds the moment it is revoked', function (): void {
    $session = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, ['profile:read']);
    $device = ApiDevice::query()->findOrFail($session->deviceId);

    $this->sessions->revoke($device, reason: 'lost', by: $this->contact);

    expect(PersonalAccessToken::query()->where('api_device_id', $device->id)->count())->toBe(0)
        ->and(ApiRefreshToken::query()->where('api_device_id', $device->id)->count())->toBe(0)
        // A revocation that left an access token alive for another fourteen
        // minutes is a revocation somebody trusts and should not.
        ->and(fn () => $this->sessions->rotate($session->refreshToken))->toThrow(SessionRefused::class);
});

it('keeps a revoked device, with when and why', function (): void {
    $session = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, []);
    $device = ApiDevice::query()->findOrFail($session->deviceId);

    $this->sessions->revoke($device, reason: 'lost', by: $this->contact);

    $kept = ApiDevice::query()->find($device->id);

    expect($kept)->not->toBeNull()
        ->and($kept->revoked_reason)->toBe('lost')
        ->and($kept->revoked_at)->not->toBeNull();
});

it('writes a system audit row when nobody revoked it', function (): void {
    $session = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, []);
    $device = ApiDevice::query()->findOrFail($session->deviceId);

    $this->sessions->revoke($device, reason: 'reused');

    $row = AuditLog::query()
        ->where('action', 'identity.device.revoked')
        ->where('target_id', $device->id)
        ->firstOrFail();

    // A record whose author was invented would be a record that lied.
    expect($row->actor_id)->toBeNull();
});

it('refuses a device whose owner can no longer sign in, and ends it', function (): void {
    $session = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, []);

    $this->contact->forceFill(['portal_access' => false])->save();

    expect(fn () => $this->sessions->rotate($session->refreshToken))
        ->toThrow(function (SessionRefused $e): void {
            expect($e->reason())->toBe('owner_unavailable');
        });

    expect(ApiDevice::query()->findOrFail($session->deviceId)->isLive())->toBeFalse();
});

it('asks the same question of a staff owner', function (): void {
    $staff = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $session = $this->sessions->open($staff, 'Pixel', DevicePlatform::Android, [], staff: true);

    $staff->forceFill(['status' => AccountStatus::Suspended])->save();

    expect(fn () => $this->sessions->rotate($session->refreshToken, staff: true))
        ->toThrow(function (SessionRefused $e): void {
            expect($e->reason())->toBe('owner_unavailable');
        });
});

it('never guesses a platform from something a client controls', function (): void {
    expect(DevicePlatform::match('iOS'))->toBe(DevicePlatform::Ios)
        ->and(DevicePlatform::match('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)'))->toBe(DevicePlatform::Other)
        ->and(DevicePlatform::match(null))->toBe(DevicePlatform::Other);
});

it('leaves a token that belongs to no device alone', function (): void {
    // An integration that has run for a year: null expiry, no device, and
    // nothing in this phase touches it.
    $existing = $this->contact->createToken('Server in a rack', ['profile:read']);

    $session = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, []);
    $device = ApiDevice::query()->findOrFail($session->deviceId);

    $this->sessions->revoke($device, reason: 'lost');

    expect(PersonalAccessToken::query()->find($existing->accessToken->getKey()))->not->toBeNull();
});

/**
 * The screen, and the button on it.
 *
 * Rendering proves the props; only a request proves the path the form posts
 * to is the path the router serves — the rule `AdminActionRoutesTest` exists
 * for, applied by hand where the route is not an admin one.
 */
it('lists a device on the security screen and revokes it from there', function (): void {
    $session = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, ['profile:read']);

    $this->actingAs($this->contact, 'client')
        ->get('/security')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('devices', 1)
            ->where('devices.0.name', 'iPhone')
            // Two fields: the value and the word.
            ->where('devices.0.platform', 'ios')
            ->where('devices.0.platformLabel', 'iPhone or iPad')
            ->where('devices.0.revokedAt', null));

    $this->actingAs($this->contact, 'client')
        ->delete('/security/devices/'.$session->deviceId)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ApiDevice::query()->findOrFail($session->deviceId)->isLive())->toBeFalse();
});

it('keeps a revoked device on the screen, with why', function (): void {
    $session = $this->sessions->open($this->contact, 'iPhone', DevicePlatform::Ios, []);
    $this->sessions->revoke(ApiDevice::query()->findOrFail($session->deviceId), reason: 'reused');

    $this->actingAs($this->contact, 'client')
        ->get('/security')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('devices', 1)
            ->where('devices.0.revokedReason', 'A key was presented twice'));
});

/**
 * A 404 rather than a 403: a 403 confirms the row exists, which on a table
 * of somebody's devices is an answer nobody is owed.
 */
it('answers 404 for a device belonging to somebody else', function (): void {
    $other = Contact::factory()->create(['portal_access' => true]);
    $session = $this->sessions->open($other, 'Their phone', DevicePlatform::Android, []);

    $this->actingAs($this->contact, 'client')
        ->delete('/security/devices/'.$session->deviceId)
        ->assertNotFound();

    // Read outside the boundary on purpose: the other contact's own
    // organization is what hides the row from this one, which is the
    // narrowing doing its job before the owner check is even reached.
    $theirs = ApiDevice::query()
        ->withoutGlobalScope('organization')
        ->findOrFail($session->deviceId);

    expect($theirs->isLive())->toBeTrue();
});
