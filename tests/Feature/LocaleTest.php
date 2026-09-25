<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Locales;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The interface in the language of the person reading it.
 *
 * Every string in this product is translatable and every one of them was
 * written twice, and none of it was reachable: `locale` on a staff user and
 * on a contact was read by exactly one thing — the notifier, choosing which
 * wording to email — while the panel rendered `config('app.locale')` for
 * everybody. These tests are about the two halves of the fix: the request
 * renders in the reader's language, and there is a way for them to say what
 * that is.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    config()->set('platform.locales', ['en', 'tr']);

    $this->staff = StaffUser::factory()->create(['locale' => null]);
    $this->staff->assignRole(SystemRole::Administrator);
    $this->staff = $this->staff->fresh();

    $this->customer = Customer::factory()->create(['locale' => null]);
    $this->contact = Contact::factory()->forCustomer($this->customer)->primary()->create([
        'locale' => null,
    ]);
    $this->contact->assignRole(SystemRole::AccountOwner);
    $this->contact = $this->contact->fresh();
});

it('renders the admin in the language the operator chose', function (): void {
    $this->staff->forceFill(['locale' => 'tr'])->save();

    $this->actingAs($this->staff->fresh(), 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertSee('lang="tr"', false);

    expect(app()->getLocale())->toBe('tr');
});

it('falls back to the installation when nobody stated a preference', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertSee('lang="en"', false);
});

/**
 * A contact with no preference of their own gets their account's. That is
 * the language somebody chose when the account was opened, and it is
 * already the one their invoices arrive in.
 */
it('reads a customer language for a contact who has not chosen one', function (): void {
    $this->customer->forceFill(['locale' => 'tr'])->save();

    $this->actingAs($this->contact, 'client')
        ->get('/client')
        ->assertOk()
        ->assertSee('lang="tr"', false);
});

it('prefers the contact over the account', function (): void {
    $this->customer->forceFill(['locale' => 'tr'])->save();
    $this->contact->forceFill(['locale' => 'en'])->save();

    $this->actingAs($this->contact->fresh(), 'client')
        ->get('/client')
        ->assertOk()
        ->assertSee('lang="en"', false);
});

/**
 * A row from an older import, or one edited by hand, cannot make the panel
 * render its own translation keys at somebody.
 */
it('ignores a language this installation does not ship', function (): void {
    $this->staff->forceFill(['locale' => 'de'])->save();

    $this->actingAs($this->staff->fresh(), 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertSee('lang="en"', false);
});

it('lets an operator change their own language', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->put('/admin/locale', ['locale' => 'tr'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->staff->fresh()->locale)->toBe('tr');
});

it('lets a customer change theirs', function (): void {
    $this->actingAs($this->contact, 'client')
        ->put('/locale', ['locale' => 'tr'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->contact->fresh()->locale)->toBe('tr');
});

it('refuses a language this installation does not ship', function (): void {
    $this->actingAs($this->staff, 'staff')
        ->put('/admin/locale', ['locale' => 'de'])
        ->assertSessionHasErrors('locale');

    expect($this->staff->fresh()->locale)->toBeNull();
});

it('is not reachable by somebody who is not signed in', function (): void {
    $this->put('/admin/locale', ['locale' => 'tr'])->assertRedirect('/admin/login');
    $this->put('/locale', ['locale' => 'tr'])->assertRedirect('/login');
});

/**
 * The list the switch is drawn from, and the list the write is checked
 * against, are the same list. Two copies is how a language appears in a
 * control and is then refused.
 */
it('offers exactly the languages it accepts', function (): void {
    config()->set('platform.locales', ['en']);

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/locale', ['locale' => 'tr'])
        ->assertSessionHasErrors('locale');

    expect(Locales::options())->toBe([['value' => 'en', 'label' => 'EN']]);
});
