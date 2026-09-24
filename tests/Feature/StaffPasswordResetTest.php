<?php

declare(strict_types=1);

use App\Infrastructure\Identity\Models\StaffUser;
use Database\Seeders\ProviderOrganizationSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * A staff member who has forgotten their password.
 *
 * Nothing had ever driven either end of this, which is an uncomfortable gap:
 * it is the only way back into an installation whose operator is locked out,
 * and it writes a password.
 *
 * Two properties matter more than the happy path and both are asserted: the
 * answer is the same whether or not the address exists — anything else tells an
 * attacker which addresses are registered — and the token is for the staff
 * broker, so a client's token cannot set a staff password.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);

    $this->staff = StaffUser::factory()->create([
        'email' => 'operator@example.test',
        'password' => Hash::make('the-old-password'),
    ]);
});

it('answers the same way for an address it knows and one it does not', function (): void {
    $known = $this->post('/admin/forgot-password', ['email' => 'operator@example.test']);
    $unknown = $this->post('/admin/forgot-password', ['email' => 'nobody@example.test']);

    $known->assertRedirect();
    $unknown->assertRedirect();

    expect($known->getSession()->get('status'))
        ->toBe($unknown->getSession()->get('status'));

    // And only the address that exists got a token.
    expect(
        $this->getConnection()->table('password_reset_tokens')
            ->where('email', 'operator@example.test')
            ->exists()
    )->toBeTrue()
        ->and(
            $this->getConnection()->table('password_reset_tokens')
                ->where('email', 'nobody@example.test')
                ->exists()
        )->toBeFalse();
});

it('sets a new password with a staff token and lets them sign in with it', function (): void {
    $token = Password::broker('staff_users')->createToken($this->staff);

    $this->post('/admin/reset-password', [
        'token' => $token,
        'email' => $this->staff->email,
        'password' => 'a-much-longer-new-password-9',
        'password_confirmation' => 'a-much-longer-new-password-9',
    ])->assertRedirect();

    expect(Hash::check('a-much-longer-new-password-9', $this->staff->fresh()->password))->toBeTrue();

    $this->post('/admin/login', [
        'email' => $this->staff->email,
        'password' => 'a-much-longer-new-password-9',
    ])->assertRedirect();

    $this->assertAuthenticated('staff');
});

it('refuses a token that is not this address', function (): void {
    $other = StaffUser::factory()->create(['email' => 'someone.else@example.test']);

    $this->post('/admin/reset-password', [
        'token' => Password::broker('staff_users')->createToken($other),
        'email' => $this->staff->email,
        'password' => 'a-much-longer-new-password-9',
        'password_confirmation' => 'a-much-longer-new-password-9',
    ])->assertSessionHasErrors();

    expect(Hash::check('the-old-password', $this->staff->fresh()->password))->toBeTrue();
});
