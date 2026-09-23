<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Crm\AddressType;
use App\Domain\Crm\CustomerStatus;
use App\Infrastructure\Crm\Models\Address;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\Note;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Adding a client the way an operator does it: the company, the person and
 * the address in one go, on the telephone, once.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    CurrencyRecord::factory()->create(['code' => 'TRY', 'name' => 'Turkish Lira']);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

function clientPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Zeynep',
        'last_name' => 'Kaya',
        'email' => 'zeynep@example.test',
        'phone' => '+90 501 234 56 78',
        'company_name' => 'Kaya Bilişim',
        'status' => CustomerStatus::Active->value,
        'currency_code' => 'TRY',
        'address_line_one' => 'Bağdat Caddesi 1',
        'city' => 'İstanbul',
        'country_code' => 'TR',
    ], $overrides);
}

it('offers the form with what this installation actually has', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/clients/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Customers/Create')
            ->has('currencies')
            ->has('locales')
            ->has('roles', 2)
            // The roles say what they can do, read from the roles
            // themselves rather than written out on the screen.
            ->has('roles.0.can')
            ->where('defaults.country', 'TR'));
});

it('creates the company, the person and the address together', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/clients', clientPayload())
        ->assertRedirect();

    $customer = Customer::query()->where('company_name', 'Kaya Bilişim')->firstOrFail();

    expect($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->currency_code)->toBe('TRY');

    $contact = Contact::query()->where('customer_id', $customer->id)->firstOrFail();

    expect($contact->is_primary)->toBeTrue()
        ->and($contact->portal_access)->toBeTrue()
        ->and($contact->email)->toBe('zeynep@example.test')
        // No password was given, so there is none — and the account cannot
        // be signed into until they set one from the reset link.
        ->and($contact->password)->toBeNull()
        ->and($contact->hasRole(SystemRole::AccountOwner))->toBeTrue();

    $address = Address::query()
        ->where('addressable_type', Customer::class)
        ->where('addressable_id', $customer->id)
        ->firstOrFail();

    expect($address->type)->toBe(AddressType::Billing)
        ->and($address->city)->toBe('İstanbul')
        ->and($address->is_default)->toBeTrue();
});

/**
 * A country on its own is not an address, and storing one would produce an
 * invoice addressed to a country.
 */
it('stores no address when there is no street', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/clients', clientPayload([
            'address_line_one' => '',
            'city' => '',
        ]))
        ->assertRedirect();

    $customer = Customer::query()->where('company_name', 'Kaya Bilişim')->firstOrFail();

    expect(Address::query()->where('addressable_id', $customer->id)->count())->toBe(0);
});

it('insists on a country once there is a street', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/clients', clientPayload(['country_code' => '']))
        ->assertSessionHasErrors('country_code');

    expect(Customer::query()->where('company_name', 'Kaya Bilişim')->exists())->toBeFalse();
});

it('refuses a second account on the same email address', function (): void {
    Contact::factory()->create(['email' => 'zeynep@example.test']);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/clients', clientPayload())
        ->assertSessionHasErrors('email');
});

it('holds a password to the same rules a customer sets for themselves', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/clients', clientPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))
        ->assertSessionHasErrors('password');
});

it('records the operator note against their name, and never to the customer', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/clients', clientPayload(['notes' => 'Rang about the migration.']))
        ->assertRedirect();

    $note = Note::query()->firstOrFail();

    expect($note->body)->toBe('Rang about the migration.')
        ->and($note->author_label)->toBe($this->admin->name)
        ->and($note->is_customer_visible)->toBeFalse();
});

it('starts a client with the billing preferences the form sent', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/clients', clientPayload([
            'send_overdue_notices' => false,
            'automatic_suspension' => false,
            'separate_invoices' => true,
        ]))
        ->assertRedirect();

    $customer = Customer::query()->where('company_name', 'Kaya Bilişim')->firstOrFail();

    expect($customer->send_overdue_notices)->toBeFalse()
        ->and($customer->automatic_suspension)->toBeFalse()
        ->and($customer->separate_invoices)->toBeTrue();
});

/**
 * A database default fills the row but leaves the model in memory without
 * the attribute, and a cast reads that absence as null.
 */
it('answers the billing preferences before the row is read back', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/clients', clientPayload())
        ->assertRedirect();

    $customer = Customer::query()->where('company_name', 'Kaya Bilişim')->firstOrFail();

    expect($customer->send_overdue_notices)->toBeTrue()
        ->and($customer->automatic_suspension)->toBeTrue()
        ->and($customer->separate_invoices)->toBeFalse();
});

it('refuses somebody without the permission to manage customers', function (): void {
    $support = StaffUser::factory()->create();
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->post('/admin/clients', clientPayload())
        ->assertForbidden();

    expect(Customer::query()->where('company_name', 'Kaya Bilişim')->exists())->toBeFalse();
});
