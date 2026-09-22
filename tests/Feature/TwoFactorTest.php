<?php

declare(strict_types=1);

use App\Application\Identity\TwoFactorAuthenticator;
use App\Infrastructure\Identity\Models\LoginHistory;
use App\Infrastructure\Identity\Models\StaffUser;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    $this->withoutVite();

    $this->secret = 'JBSWY3DPEHPK3PXP';
    $this->google = app(Google2FA::class);

    $this->staff = StaffUser::factory()->create([
        'email' => 'tfa@example.test',
        'password' => 'correct-horse-battery-staple-1!',
    ]);
});

function currentCode(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

it('does not challenge an account with two-factor turned off', function (): void {
    $this->post('/admin/login', [
        'email' => $this->staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ])->assertRedirect('/admin');

    expect(auth('staff')->check())->toBeTrue();
});

it('does not challenge on a secret that was never confirmed', function (): void {
    // Enrolment that was started and abandoned must not lock anyone out.
    $this->staff->forceFill(['two_factor_secret' => $this->secret])->save();

    $this->post('/admin/login', [
        'email' => $this->staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ])->assertRedirect('/admin');

    expect(auth('staff')->check())->toBeTrue();
});

it('holds the session until the code is entered', function (): void {
    $this->staff->forceFill([
        'two_factor_secret' => $this->secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/admin/login', [
        'email' => $this->staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ])->assertRedirect('/admin/two-factor-challenge');

    // Credentials were right, but no session exists yet.
    expect(auth('staff')->check())->toBeFalse();

    $this->post('/admin/two-factor-challenge', ['code' => currentCode($this->secret)])
        ->assertRedirect('/admin');

    expect(auth('staff')->check())->toBeTrue();
});

it('refuses a wrong code and records it', function (): void {
    $this->staff->forceFill([
        'two_factor_secret' => $this->secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/admin/login', [
        'email' => $this->staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    $this->post('/admin/two-factor-challenge', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect(auth('staff')->check())->toBeFalse()
        ->and(LoginHistory::query()->withoutGlobalScope('organization')
            ->where('failure_reason', 'invalid_two_factor_code')->count())
        ->toBe(1);
});

it('will not complete a client sign-in on the admin challenge screen', function (): void {
    $this->staff->forceFill([
        'two_factor_secret' => $this->secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/admin/login', [
        'email' => $this->staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    // The pending sign-in names the staff guard, so the client challenge
    // must refuse to act on it.
    $this->post('/two-factor-challenge', ['code' => currentCode($this->secret)])
        ->assertRedirect('/login');

    expect(auth('client')->check())->toBeFalse()
        ->and(auth('staff')->check())->toBeFalse();
});

it('sends someone with no pending sign-in back to the login page', function (): void {
    $this->get('/admin/two-factor-challenge')->assertRedirect('/admin/login');
});

it('enrols in three steps and hands back recovery codes', function (): void {
    $this->actingAs($this->staff, 'staff');

    $this->post('/admin/security/two-factor')->assertRedirect();

    $staff = $this->staff->fresh();
    expect($staff->hasPendingTwoFactorSetup())->toBeTrue()
        ->and($staff->hasTwoFactorEnabled())->toBeFalse();

    $secret = (string) $staff->two_factor_secret;

    $response = $this->post('/admin/security/two-factor/confirm', ['code' => currentCode($secret)]);

    $response->assertSessionHas('recoveryCodes');

    expect($this->staff->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($response->getSession()->get('recoveryCodes'))->toHaveCount(8);
});

it('refuses to confirm enrolment with a wrong code', function (): void {
    $this->actingAs($this->staff, 'staff');
    $this->post('/admin/security/two-factor');

    $this->post('/admin/security/two-factor/confirm', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect($this->staff->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('accepts a recovery code once and never again', function (): void {
    $authenticator = app(TwoFactorAuthenticator::class);

    $this->staff->forceFill([
        'two_factor_secret' => $this->secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $codes = $authenticator->regenerateRecoveryCodes($this->staff);
    $code = $codes[0];

    $this->post('/admin/login', [
        'email' => $this->staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    $this->post('/admin/two-factor-challenge', ['code' => $code, 'recovery' => true])
        ->assertRedirect('/admin');

    expect(auth('staff')->check())->toBeTrue()
        ->and($this->staff->fresh()->twoFactorRecoveryCodeHashes())->toHaveCount(7);

    // A second use of the same code fails.
    $this->post('/admin/logout');

    $this->post('/admin/login', [
        'email' => $this->staff->email,
        'password' => 'correct-horse-battery-staple-1!',
    ]);

    $this->post('/admin/two-factor-challenge', ['code' => $code, 'recovery' => true])
        ->assertSessionHasErrors('code');

    expect(auth('staff')->check())->toBeFalse();
});

it('stores recovery codes hashed, never in the clear', function (): void {
    $codes = app(TwoFactorAuthenticator::class)->regenerateRecoveryCodes($this->staff);

    $stored = $this->staff->fresh()->twoFactorRecoveryCodeHashes();

    foreach ($stored as $hash) {
        expect($hash)->not->toBeIn($codes)
            ->and($hash)->toStartWith('$2y$');
    }
});

it('turns two-factor off and clears the secret with it', function (): void {
    $this->staff->forceFill([
        'two_factor_secret' => $this->secret,
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($this->staff, 'staff')->delete('/admin/security/two-factor');

    $staff = $this->staff->fresh();

    expect($staff->hasTwoFactorEnabled())->toBeFalse()
        ->and($staff->two_factor_secret)->toBeNull()
        ->and($staff->twoFactorRecoveryCodeHashes())->toBe([]);
});

it('encrypts the secret at rest', function (): void {
    $this->staff->forceFill(['two_factor_secret' => $this->secret])->save();

    $raw = DB::table('staff_users')->where('id', $this->staff->id)->value('two_factor_secret');

    expect($raw)->not->toBe($this->secret)
        ->and($this->staff->fresh()->two_factor_secret)->toBe($this->secret);
});
