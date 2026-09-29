<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Identity\TwoFactorAuthenticator;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Api\Models\ApiDevice;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

/**
 * Where a device session begins, renews and ends (ADR 0049).
 *
 * The endpoints themselves are thin. What is worth testing is that none of
 * them is a way round something the browser already enforces: a second
 * factor cannot be skipped, a scope the holder may not use is dropped rather
 * than granted, and every refusal looks identical from outside.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->contact = Contact::factory()->create([
        'email' => 'ayse@example.test',
        'password' => 'correct-horse-battery',
        'portal_access' => true,
    ]);

    $this->contact->assignRole(SystemRole::AccountOwner);
    $this->contact = $this->contact->fresh();
});

function openSession(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/auth/token', array_merge([
        'email' => 'ayse@example.test',
        'password' => 'correct-horse-battery',
        'device_name' => 'iPhone',
        'platform' => 'ios',
        'scopes' => ['profile:read'],
    ], $overrides));
}

it('hands back a pair and records the device', function (): void {
    $response = openSession()->assertCreated();

    $response->assertJsonStructure([
        'data' => [
            'device_id',
            'access_token',
            'access_expires_at',
            'refresh_token',
            'refresh_expires_at',
        ],
    ]);

    $device = ApiDevice::query()->firstOrFail();

    expect($device->name)->toBe('iPhone')
        ->and($device->platform->value)->toBe('ios');
});

it('gives the access token an expiry, unlike a token issued to a server', function (): void {
    openSession()->assertCreated();

    // Today's tokens do not expire, which is right for a machine in a rack
    // and wrong for a phone left in a taxi (ADR 0044).
    expect(PersonalAccessToken::query()->firstOrFail()->expires_at)->not->toBeNull();
});

it('refuses a wrong password the same way it refuses an unknown address', function (): void {
    $wrong = openSession(['password' => 'not-it'])->assertUnauthorized();
    $unknown = openSession(['email' => 'nobody@example.test'])->assertUnauthorized();

    // Identical, so the response does not say which accounts exist.
    expect($wrong->json('error.code'))->toBe($unknown->json('error.code'))
        ->and($wrong->json('error.message'))->toBe($unknown->json('error.message'));
});

/**
 * A device session that skipped the second factor would be two-factor
 * authentication with an opt-out, which is no two-factor authentication.
 */
it('will not open a session without the second factor when one is enabled', function (): void {
    $twoFactor = app(TwoFactorAuthenticator::class);
    $secret = $twoFactor->beginEnrolment($this->contact);
    $twoFactor->confirmEnrolment($this->contact, app(Google2FA::class)->getCurrentOtp($secret));

    openSession()->assertUnauthorized();

    openSession([
        'two_factor_code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertCreated();
});

it('drops a scope the holder is not permitted to use', function (): void {
    // A contact with no role holds no portal permissions at all.
    $stranger = Contact::factory()->create([
        'email' => 'stranger@example.test',
        'password' => 'correct-horse-battery',
        'portal_access' => true,
    ]);

    test()->postJson('/api/v1/auth/token', [
        'email' => 'stranger@example.test',
        'password' => 'correct-horse-battery',
        'device_name' => 'iPhone',
        'scopes' => ['profile:read', 'tickets:write'],
    ])->assertCreated();

    $token = PersonalAccessToken::query()
        ->where('tokenable_id', $stranger->id)
        ->firstOrFail();

    // Dropped rather than refused: a scope the holder cannot use would mean
    // nothing at request time anyway.
    expect($token->abilities)->toBe([]);
});

it('renews a session and refuses the token it replaced', function (): void {
    $first = openSession()->assertCreated();

    $second = test()->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $first->json('data.refresh_token'),
    ])->assertOk();

    expect($second->json('data.refresh_token'))->not->toBe($first->json('data.refresh_token'));

    test()->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $first->json('data.refresh_token'),
    ])->assertUnauthorized();

    // And the device is gone, which is the whole mechanism.
    expect(ApiDevice::query()->firstOrFail()->isLive())->toBeFalse();
});

it('answers a nonsense refresh token exactly as it answers a spent one', function (): void {
    $first = openSession()->assertCreated();

    test()->postJson('/api/v1/auth/refresh', ['refresh_token' => $first->json('data.refresh_token')])->assertOk();

    $spent = test()->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $first->json('data.refresh_token'),
    ])->assertUnauthorized();

    $nonsense = test()->postJson('/api/v1/auth/refresh', [
        'refresh_token' => 'not-a-token',
    ])->assertUnauthorized();

    expect($spent->json('error.message'))->toBe($nonsense->json('error.message'));
});

it('signs the calling device out, everywhere', function (): void {
    $session = openSession()->assertCreated();

    test()->withToken($session->json('data.access_token'))
        ->deleteJson('/api/v1/auth/token')
        ->assertOk();

    expect(ApiDevice::query()->firstOrFail()->isLive())->toBeFalse();

    // The token it was called with is gone with it.
    test()->withToken($session->json('data.access_token'))
        ->getJson('/api/v1/profile')
        ->assertUnauthorized();
});

/**
 * A device id in a payload would be a way to sign somebody else's phone out
 * of an account you share, so the device comes from the token instead.
 */
it('will not sign out a token that belongs to no device', function (): void {
    $token = $this->contact->createToken('Server in a rack', ['profile:read']);

    test()->withToken($token->plainTextToken)
        ->deleteJson('/api/v1/auth/token')
        ->assertUnauthorized();

    expect(PersonalAccessToken::query()->find($token->accessToken->getKey()))->not->toBeNull();
});

it('refuses a contact who may not use the portal', function (): void {
    $this->contact->forceFill(['portal_access' => false])->save();

    openSession()->assertUnauthorized();
});

it('validates what it is given before it checks anything', function (): void {
    test()->postJson('/api/v1/auth/token', ['email' => 'not-an-address'])
        ->assertStatus(422);
});
