<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Crm\AddressType;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Crm\CustomFieldType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\Address;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\LoginHistory;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Service;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

it('hides closed accounts until somebody asks for them', function (): void {
    Customer::factory()->create(['company_name' => 'Still Trading']);
    Customer::factory()->create([
        'company_name' => 'Gone Last Year',
        'status' => CustomerStatus::Closed->value,
    ]);

    // A closed customer is a record the accounts department keeps, not
    // somebody an operator is working with.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.company', 'Still Trading'));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers?inactive=1')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers.data', 2));
});

it('shows a closed account when it was asked for by status', function (): void {
    Customer::factory()->create(['status' => CustomerStatus::Closed->value]);

    // An explicit status filter wins over the default: somebody who asked
    // for closed accounts means it.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers?status=closed')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers.data', 1));
});

it('lists the columns an operator scans', function (): void {
    $customer = Customer::factory()->create(['company_name' => 'Northwind Supply']);

    Contact::factory()->forCustomer($customer)->primary()->create([
        'first_name' => 'Ines',
        'last_name' => 'Caetano',
        'email' => 'ines@northwind.test',
    ]);

    Service::factory()->forCustomer($customer)->count(2)->create([
        'status' => ServiceStatus::Active->value,
    ]);
    Service::factory()->forCustomer($customer)->create([
        'status' => ServiceStatus::Suspended->value,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('customers.data.0.firstName', 'Ines')
            ->where('customers.data.0.lastName', 'Caetano')
            ->where('customers.data.0.company', 'Northwind Supply')
            ->where('customers.data.0.email', 'ines@northwind.test')
            // Active, with anything not active alongside it.
            ->where('customers.data.0.activeServices', 2)
            ->where('customers.data.0.inactiveServices', 1));
});

it('counts services in the list query rather than per row', function (): void {
    $customers = Customer::factory()->count(3)->create();

    foreach ($customers as $customer) {
        Service::factory()->forCustomer($customer)->create();
    }

    // Strict mode reports a lazy load once a query returns more than one
    // row, so three customers is what proves the counts are not N+1.
    $this->actingAs($this->admin, 'staff')->get('/admin/customers')->assertOk();
});

it('finds somebody by a postcode on a returned letter', function (): void {
    $customer = Customer::factory()->create(['company_name' => 'Northwind Supply']);
    Customer::factory()->create(['company_name' => 'Somebody Else']);

    Address::factory()->create([
        'addressable_type' => Customer::class,
        'addressable_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'type' => AddressType::Billing->value,
        'postal_code' => 'D02 XY45',
        'city' => 'Dublin',
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers?postcode=D02')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.company', 'Northwind Supply'));
});

it('finds a business account and an individual separately', function (): void {
    Customer::factory()->create(['company_name' => 'Northwind Supply']);
    Customer::factory()->create(['company_name' => null, 'legal_name' => null]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers?corporate=1')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers.data', 1));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers?corporate=0')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers.data', 1));
});

it('searches a custom field this installation defined', function (): void {
    $definition = CustomFieldDefinition::factory()->create([
        'organization_id' => $this->admin->organization_id,
        'entity_type' => CustomFieldEntity::Customer->value,
        'key' => 'tax_office',
        'label' => 'Vergi Dairesi',
        'type' => CustomFieldType::Text->value,
    ]);

    $customer = Customer::factory()->create(['company_name' => 'Northwind Supply']);
    Customer::factory()->create(['company_name' => 'Somebody Else']);

    $customer->customFieldValues()->create([
        'organization_id' => $customer->organization_id,
        'definition_id' => $definition->id,
        'value' => 'Kadıköy',
    ]);

    // Anything local is a custom field, and every one an installation has
    // defined is searchable without this screen being edited.
    // Encoded, because a Turkish tax office is exactly the case where an
    // unencoded query string quietly stops matching.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers?custom[tax_office]='.urlencode('Kadıköy'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.company', 'Northwind Supply'));
});

it('offers the custom fields it actually has, typed as they were defined', function (): void {
    CustomFieldDefinition::factory()->create([
        'organization_id' => $this->admin->organization_id,
        'entity_type' => CustomFieldEntity::Customer->value,
        'key' => 'id_number',
        'label' => 'T.C. Kimlik Numarası',
        'type' => CustomFieldType::Text->value,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('schema.customFields.0.label', 'T.C. Kimlik Numarası')
            ->where('schema.customFields.0.type', 'text'));
});

it('ignores a filter nobody defined rather than matching nothing', function (): void {
    Customer::factory()->create();

    // A stale bookmark must not silently return an empty list and make an
    // operator think the customer is gone.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers?custom[not_a_field]=anything')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers.data', 1));
});

it('lists the people who can sign in, and nobody else', function (): void {
    $customer = Customer::factory()->create();

    Contact::factory()->forCustomer($customer)->primary()->create([
        'first_name' => 'Ines',
        'email' => 'ines@northwind.test',
    ]);

    Contact::factory()->forCustomer($customer)->create([
        'first_name' => 'Accounts',
        'email' => 'accounts@northwind.test',
        'portal_access' => false,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customer-users')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Customers/Users')
            // A person on a customer's record who was never given a login
            // is not a user, and listing them makes "why can they not log
            // in" answerable with "they were never meant to".
            ->has('users.data', 1)
            ->where('users.data.0.email', 'ines@northwind.test'));
});

it('shows the last successful login, not the last attempt', function (): void {
    $customer = Customer::factory()->create();
    $contact = Contact::factory()->forCustomer($customer)->primary()->create();

    $succeededAt = now()->subDays(3);

    LoginHistory::query()->create([
        'subject_type' => Contact::class,
        'subject_id' => $contact->id,
        'organization_id' => $contact->organization_id,
        'guard' => 'client',
        'successful' => true,
        'occurred_at' => $succeededAt,
    ]);

    LoginHistory::query()->create([
        'subject_type' => Contact::class,
        'subject_id' => $contact->id,
        'organization_id' => $contact->organization_id,
        'guard' => 'client',
        'successful' => false,
        'occurred_at' => now(),
    ]);

    // A failed attempt is not a login, and showing one would answer the
    // question wrongly at the worst moment.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customer-users')
        ->assertOk();

    $lastLogin = Contact::query()->whereKey($contact->id)->value('id');

    expect($lastLogin)->toBe($contact->id);

    $response = $this->actingAs($this->admin, 'staff')->get('/admin/customer-users');

    /** @var array<string, mixed> $row */
    $row = $response->viewData('page')['props']['users']['data'][0];

    expect($row['lastLoginAt'])->toBeString()
        ->and($row['lastLoginAt'])->toStartWith($succeededAt->toDateString());
});

it('searches users by name or email', function (): void {
    $customer = Customer::factory()->create();

    Contact::factory()->forCustomer($customer)->primary()->create(['email' => 'ines@northwind.test']);
    Contact::factory()->forCustomer($customer)->create(['email' => 'other@elsewhere.test']);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customer-users?search=northwind')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('users.data', 1));
});

it('changes a password, ends every session and records why', function (): void {
    $customer = Customer::factory()->create();
    $contact = Contact::factory()->forCustomer($customer)->primary()->create();

    $contact->createToken('Integration');

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/customer-users/'.$contact->id.'/password', [
            'password' => 'Str0ng-Enough!Really',
            'password_confirmation' => 'Str0ng-Enough!Really',
            'reason' => 'Customer called, verified by invoice number.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Hash::check('Str0ng-Enough!Really', (string) $contact->fresh()?->password))->toBeTrue()
        // Every token is now somebody else's idea of their password.
        ->and($contact->tokens()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'identity.password_set_by_staff')->count())
        ->toBe(1);
});

it('refuses to change a password without a reason', function (): void {
    $customer = Customer::factory()->create();
    $contact = Contact::factory()->forCustomer($customer)->primary()->create();

    // "Because they asked on the phone" in an audit record is the
    // difference between a procedure and an incident.
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/customer-users/'.$contact->id.'/password', [
            'password' => 'Str0ng-Enough!Really',
            'password_confirmation' => 'Str0ng-Enough!Really',
        ])
        ->assertSessionHasErrors('reason');
});

it('refuses to manage a user who is not one', function (): void {
    $customer = Customer::factory()->create();
    $contact = Contact::factory()->forCustomer($customer)->create(['portal_access' => false]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/customer-users/'.$contact->id.'/reset')
        ->assertNotFound();
});

it('refuses manage users to staff without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin/customer-users')
        ->assertForbidden();
});
