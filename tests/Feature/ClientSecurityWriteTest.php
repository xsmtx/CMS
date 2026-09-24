<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\AuthenticatedSession;
use App\Infrastructure\Identity\Models\Contact;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;

/**
 * A customer looking after their own account.
 *
 * The screens are shared with the staff area — one controller, the guard read
 * from the route-name prefix — and the *staff* half had tests. The client half
 * had none, on the endpoints that turn two-factor on, hand over recovery codes,
 * revoke a session and reset a forgotten password. Those are the ones that
 * decide who can get into somebody's account.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create();

    $this->contact = Contact::factory()
        ->forCustomer($this->customer)
        ->primary()
        ->create([
            'email' => 'customer@example.test',
            'password' => Hash::make('the-old-password-1'),
        ]);

    $this->contact->assignRole(SystemRole::AccountOwner);
    $this->contact = $this->contact->fresh();
});

it('enrols in two-factor, confirms it with a real code and is given recovery codes', function (): void {
    $this->actingAs($this->contact, 'client')
        ->post('/security/two-factor')
        ->assertRedirect();

    $secret = $this->contact->fresh()->two_factor_secret;

    // Begun, not enabled: a secret with no confirmation is somebody who closed
    // the tab, and signing them out of their account for it would be wrong.
    expect($secret)->not->toBeNull()
        ->and($this->contact->fresh()->two_factor_confirmed_at)->toBeNull();

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($this->contact->fresh(), 'client')
        ->post('/security/two-factor/confirm', ['code' => $code])
        ->assertRedirect();

    expect($this->contact->fresh()->two_factor_confirmed_at)->not->toBeNull();

    // Flashed, never stored in the clear: this is the only moment the plain
    // codes exist.
    $codes = $response->getSession()->get('recoveryCodes');

    expect($codes)->toBeArray()
        ->and(count($codes))->toBeGreaterThan(1);
});

it('refuses a wrong code and leaves two-factor off', function (): void {
    $this->actingAs($this->contact, 'client')->post('/security/two-factor');

    $this->actingAs($this->contact->fresh(), 'client')
        ->post('/security/two-factor/confirm', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect($this->contact->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('regenerates its recovery codes, and only once two-factor is on', function (): void {
    // Nothing to regenerate yet, so the endpoint is not there.
    $this->actingAs($this->contact, 'client')
        ->post('/security/two-factor/recovery-codes')
        ->assertNotFound();

    $this->actingAs($this->contact, 'client')->post('/security/two-factor');

    $secret = $this->contact->fresh()->two_factor_secret;

    $this->actingAs($this->contact->fresh(), 'client')->post('/security/two-factor/confirm', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ]);

    $before = $this->contact->fresh()->two_factor_recovery_codes;

    $response = $this->actingAs($this->contact->fresh(), 'client')
        ->post('/security/two-factor/recovery-codes')
        ->assertRedirect();

    expect($response->getSession()->get('recoveryCodes'))->toBeArray()
        ->and($this->contact->fresh()->two_factor_recovery_codes)->not->toBe($before);
});

it('revokes one of its own sessions and not somebody else', function (): void {
    // A session row is owned like everything else, so it is stamped with the
    // organization explicitly rather than inheriting a boundary this test has
    // not entered.
    $mine = AuthenticatedSession::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'subject_type' => $this->contact->getMorphClass(),
        'subject_id' => $this->contact->id,
        'guard' => 'client',
    ]);

    $strangerCustomer = Customer::factory()->create();
    $stranger = Contact::factory()->forCustomer($strangerCustomer)->create();

    $theirs = AuthenticatedSession::factory()->create([
        'organization_id' => $strangerCustomer->organization_id,
        'subject_type' => $stranger->getMorphClass(),
        'subject_id' => $stranger->id,
        'guard' => 'client',
    ]);

    $this->actingAs($this->contact, 'client')
        ->delete('/security/sessions/'.$mine->id)
        ->assertRedirect();

    expect(AuthenticatedSession::query()->whereKey($mine->id)->exists())->toBeFalse();

    // Somebody else's session is not theirs to end, and the answer says nothing
    // about whether it exists.
    $this->actingAs($this->contact, 'client')
        ->delete('/security/sessions/'.$theirs->id)
        ->assertNotFound();

    // Read past the boundary to assert it: the row belongs to another
    // organization, so a scoped query could not see it whether or not the
    // endpoint had deleted it — which would make this assertion pass for the
    // wrong reason.
    expect(
        AuthenticatedSession::query()
            ->withoutGlobalScope('organization')
            ->whereKey($theirs->id)
            ->exists()
    )->toBeTrue();
});

it('confirms a password before a step-up action, and refuses a wrong one', function (): void {
    $this->actingAs($this->contact, 'client')
        ->post('/confirm-password', ['password' => 'not-the-password'])
        ->assertSessionHasErrors('password');

    $this->actingAs($this->contact, 'client')
        ->post('/confirm-password', ['password' => 'the-old-password-1'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('resets a forgotten customer password and signs in with the new one', function (): void {
    $this->post('/forgot-password', ['email' => $this->contact->email])->assertRedirect();

    // A table of its own, not the staff one: two tables is the same separation
    // the two guards have everywhere else, and it is why a customer's token can
    // never set a staff password.
    expect(
        $this->getConnection()->table('contact_password_reset_tokens')
            ->where('email', $this->contact->email)
            ->exists()
    )->toBeTrue();

    $this->post('/reset-password', [
        'token' => Password::broker('contacts')->createToken($this->contact),
        'email' => $this->contact->email,
        'password' => 'a-much-longer-new-password-9',
        'password_confirmation' => 'a-much-longer-new-password-9',
    ])->assertRedirect();

    expect(Hash::check('a-much-longer-new-password-9', $this->contact->fresh()->password))->toBeTrue();

    $this->post('/login', [
        'email' => $this->contact->email,
        'password' => 'a-much-longer-new-password-9',
    ])->assertRedirect();

    $this->assertAuthenticated('client');
});

it('signs out', function (): void {
    $this->actingAs($this->contact, 'client')
        ->post('/logout')
        ->assertRedirect();

    $this->assertGuest('client');
});
