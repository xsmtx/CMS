<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Crm\AddressType;
use App\Domain\Crm\CustomerStatus;
use App\Infrastructure\Crm\Models\Address;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Infrastructure\Tax\Models\TaxSetting;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * A visitor opening their own account.
 *
 * The screen is driven as well as rendered, because a form whose buttons no
 * test presses has not been tested: rendering proves the props, and only a
 * request proves the payload the form sends is the payload the controller
 * wants.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->withoutGlobalScope('organization')
        ->whereNull('parent_id')
        ->sole();

    CurrencyRecord::factory()->forOrganization($this->provider->id)->base()->code('TRY', 'Turkish Lira')->create();
});

const REGISTER_PASSWORD = 'correct-horse-battery-staple-1!';

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Zeynep',
        'last_name' => 'Kaya',
        'email' => 'zeynep@example.test',
        'phone' => '+90 501 234 56 78',
        'password' => REGISTER_PASSWORD,
        'password_confirmation' => REGISTER_PASSWORD,
        'currency_code' => 'TRY',
    ], $overrides);
}

it('offers the form with what this installation actually trades in', function (): void {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Auth/Register')
            ->where('currency', 'TRY')
            ->where('currencies', ['TRY'])
            ->where('loginUrl', route('client.login'))
            ->has('taxIdentity.label')
            ->where('taxIdentity.requiredForBusiness', false));
});

it('creates the customer, the person and the session in one request', function (): void {
    $this->post('/register', registerPayload())
        ->assertRedirect('/client')
        ->assertSessionHasNoErrors();

    $contact = Contact::query()->withoutGlobalScope('organization')->sole();

    expect($contact->email)->toBe('zeynep@example.test')
        ->and($contact->portal_access)->toBeTrue()
        ->and($contact->is_primary)->toBeTrue()
        ->and($contact->password)->not->toBeNull()
        // Signed in as the person who just registered, through the one path
        // that grants a session.
        ->and(auth('client')->id())->toBe($contact->id)
        ->and(auth('staff')->check())->toBeFalse();
});

it('parents the customer on the organization whose shop they registered in', function (): void {
    $this->post('/register', registerPayload())->assertRedirect('/client');

    $customer = Customer::query()->withoutGlobalScope('organization')->sole();
    $organization = Organization::query()
        ->withoutGlobalScope('organization')
        ->findOrFail($customer->organization_id);

    expect($organization->parent_id)->toBe($this->provider->id)
        // Active rather than pending: `PlaceOrder` refuses a customer who
        // cannot transact, and nothing moves an account out of pending on its
        // own, so pending would be a form that produced a dead account.
        ->and($customer->status)->toBe(CustomerStatus::Active);
});

it('writes the address when there is a street, and none when there is not', function (): void {
    $this->post('/register', registerPayload([
        'company_name' => 'Kaya Bilişim',
        'address_line_one' => 'Bağdat Caddesi 1',
        'city' => 'İstanbul',
        'postal_code' => '34710',
        'country_code' => 'tr',
    ]))->assertRedirect('/client');

    $address = Address::query()->withoutGlobalScope('organization')->sole();

    expect($address->type)->toBe(AddressType::Billing)
        ->and($address->country_code)->toBe('TR')
        ->and($address->is_default)->toBeTrue();
});

it('stores no address for a country with no street', function (): void {
    $this->post('/register', registerPayload(['city' => 'İstanbul']))
        ->assertRedirect('/client');

    expect(Address::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('refuses an email address another account already uses, and writes nothing', function (): void {
    // The factory brings a customer of its own with it, so the count to watch
    // is the one it left behind rather than zero.
    Contact::factory()->create(['email' => 'zeynep@example.test']);
    $before = Customer::query()->withoutGlobalScope('organization')->count();

    $this->post('/register', registerPayload())
        ->assertSessionHasErrors('email');

    expect(Customer::query()->withoutGlobalScope('organization')->count())->toBe($before)
        ->and(auth('client')->check())->toBeFalse();
});

it('refuses a password that does not match its confirmation', function (): void {
    $this->post('/register', registerPayload(['password_confirmation' => 'something-else-1!']))
        ->assertSessionHasErrors('password');

    expect(Customer::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('holds a registration to the same password rules the admin form uses', function (): void {
    $this->post('/register', registerPayload([
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))->assertSessionHasErrors('password');
});

it('asks a company for a tax id when the seller says it must', function (): void {
    TaxSetting::factory()->forOrganization($this->provider->id)->create([
        'require_tax_id_for_business' => true,
    ]);

    $this->post('/register', registerPayload(['company_name' => 'Kaya Bilişim']))
        ->assertSessionHasErrors('tax_id');
});

it('asks an individual for no tax id, whatever the seller requires of a business', function (): void {
    TaxSetting::factory()->forOrganization($this->provider->id)->create([
        'require_tax_id_for_business' => true,
    ]);

    // A business is a company name, not a tax id. One request per test,
    // because the first successful registration signs the visitor in and
    // `guest:client` then redirects the second away from the form.
    $this->post('/register', registerPayload())
        ->assertRedirect('/client')
        ->assertSessionHasNoErrors();
});

it('refuses a currency this installation does not trade in', function (): void {
    $this->post('/register', registerPayload(['currency_code' => 'XXX']))
        ->assertSessionHasErrors('currency_code');
});

it('offers the link from the sign-in screen, and only on the customer surface', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('registerUrl', route('client.register')));

    $this->get('/admin/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('registerUrl', null));
});

it('has no such page when the installation is closed to it', function (): void {
    config()->set('platform.crm.self_registration', false);

    $this->get('/register')->assertNotFound();
    $this->post('/register', registerPayload())->assertNotFound();

    expect(Customer::query()->withoutGlobalScope('organization')->count())->toBe(0);

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('registerUrl', null));
});

it('sends a signed-in customer to their portal rather than the form', function (): void {
    $contact = Contact::factory()->create(['email' => 'already@example.test']);

    $this->actingAs($contact, 'client')
        ->get('/register')
        ->assertRedirect('/client');
});
