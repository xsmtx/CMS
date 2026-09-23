<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Identity\AccountStatus;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Crm\Models\Address;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\Note;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()->withoutGlobalScope('organization')
        ->where('type', 'provider')->sole();

    $this->admin = StaffUser::factory()->forOrganization($this->provider)->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

it('refuses the customer list without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin/customers')
        ->assertForbidden();
});

/**
 * There is one way to create a client, and it is `/admin/clients` — the
 * screen that makes the company, the first person and the address
 * together. `/admin/customers` edits a record that exists.
 */
it('creates a customer together with its organization', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/clients', [
            'first_name' => 'Ines',
            'last_name' => 'Caetano',
            'email' => 'ines@meridian.test',
            'company_name' => 'Meridian Freight',
            'status' => CustomerStatus::Active->value,
            'currency_code' => 'EUR',
        ])
        ->assertRedirect();

    $customer = Customer::query()->where('company_name', 'Meridian Freight')->sole();
    $organization = Organization::query()->withoutGlobalScope('organization')
        ->findOrFail($customer->organization_id);

    expect($organization->type->value)->toBe('customer')
        // The new organization hangs off the creator's, which is what makes
        // a reseller's customers theirs.
        ->and($organization->parent_id)->toBe($this->provider->id);
});

it('requires a company or a legal name when editing, so sole traders are possible', function (): void {
    $customer = Customer::factory()->create(['company_name' => 'Meridian Freight']);

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/customers/'.$customer->id, [
            'status' => CustomerStatus::Active->value,
            'currency_code' => 'EUR',
        ])
        ->assertSessionHasErrors('company_name');

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/customers/'.$customer->id, [
            'legal_name' => 'Ines Caetano',
            'status' => CustomerStatus::Active->value,
            'currency_code' => 'EUR',
        ])
        ->assertSessionHasNoErrors();
});

it('never shows a customer belonging to another reseller', function (): void {
    $otherReseller = Organization::factory()->reseller($this->provider)->create();
    $theirCustomer = app(OrganizationContext::class)->runAs(
        $otherReseller->id,
        fn (): Customer => Customer::factory()->forOrganization(
            Organization::factory()->customerOf($otherReseller)->create(),
        )->create(),
    );

    $reseller = Organization::factory()->reseller($this->provider)->create();
    $resellerAdmin = StaffUser::factory()->forOrganization($reseller)->create();
    $resellerAdmin->assignRole(SystemRole::Administrator);

    $this->actingAs($resellerAdmin->fresh(), 'staff')
        ->get('/admin/customers/'.$theirCustomer->id)
        ->assertNotFound();
});

it('lets the provider see a reseller customer', function (): void {
    $reseller = Organization::factory()->reseller($this->provider)->create();
    $customer = Customer::factory()->forOrganization(
        Organization::factory()->customerOf($reseller)->create(),
    )->create(['company_name' => 'Downstream Ltd']);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers/'.$customer->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Customers/Show')
            ->where('customer.companyName', 'Downstream Ltd'));
});

it('refuses a status change the state machine does not allow', function (): void {
    $customer = Customer::factory()->create(['status' => CustomerStatus::Pending->value]);

    // Pending may go to active or closed, never straight to suspended.
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/customers/'.$customer->id, [
            'company_name' => $customer->company_name,
            'status' => CustomerStatus::Suspended->value,
            'currency_code' => 'EUR',
        ])
        ->assertStatus(409);

    expect($customer->fresh()->status)->toBe(CustomerStatus::Pending);
});

it('allows a status change the state machine permits', function (): void {
    $customer = Customer::factory()->create(['status' => CustomerStatus::Active->value]);

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/customers/'.$customer->id, [
            'company_name' => $customer->company_name,
            'status' => CustomerStatus::Suspended->value,
            'currency_code' => 'EUR',
        ])
        ->assertRedirect();

    expect($customer->fresh()->status)->toBe(CustomerStatus::Suspended);
});

it('exports everything held about a customer', function (): void {
    $customer = Customer::factory()->create(['company_name' => 'Export Test']);
    $contact = Contact::factory()->forCustomer($customer)->primary()->create();

    Address::factory()->create([
        'organization_id' => $customer->organization_id,
        'addressable_type' => $customer->getMorphClass(),
        'addressable_id' => $customer->id,
    ]);

    $response = $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers/'.$customer->id.'/export');

    $response->assertOk();

    $payload = $response->json();

    expect($payload['customer']['company_name'])->toBe('Export Test')
        ->and($payload['contacts'])->toHaveCount(1)
        ->and($payload['contacts'][0]['email'])->toBe($contact->email)
        ->and($payload['addresses'])->toHaveCount(1);
});

it('never exports authentication secrets', function (): void {
    $customer = Customer::factory()->create();
    Contact::factory()->forCustomer($customer)->withTwoFactor()->create();

    $payload = $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers/'.$customer->id.'/export')
        ->json();

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('password')
        ->and($encoded)->not->toContain('two_factor_secret')
        ->and($encoded)->not->toContain('JBSWY3DPEHPK3PXP');
});

it('excludes internal notes from an export', function (): void {
    $customer = Customer::factory()->create();

    Note::factory()->create([
        'organization_id' => $customer->organization_id,
        'notable_type' => $customer->getMorphClass(),
        'notable_id' => $customer->id,
        'body' => 'Internal: chases invoices late.',
    ]);

    Note::factory()->customerVisible()->create([
        'organization_id' => $customer->organization_id,
        'notable_type' => $customer->getMorphClass(),
        'notable_id' => $customer->id,
        'body' => 'Shared with the customer.',
    ]);

    $payload = $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers/'.$customer->id.'/export')
        ->json();

    expect($payload['notes'])->toHaveCount(1)
        ->and($payload['notes'][0]['body'])->toBe('Shared with the customer.');
});

it('refuses to export without the export permission', function (): void {
    $customer = Customer::factory()->create();

    $support = StaffUser::factory()->forOrganization($this->provider)->create();
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->get('/admin/customers/'.$customer->id.'/export')
        ->assertForbidden();
});

it('erases personal data but keeps the commercial record', function (): void {
    $customer = Customer::factory()->create(['company_name' => 'To Be Erased']);
    $contact = Contact::factory()->forCustomer($customer)->withTwoFactor()->create([
        'email' => 'erase@example.test',
    ]);

    Address::factory()->create([
        'organization_id' => $customer->organization_id,
        'addressable_type' => $contact->getMorphClass(),
        'addressable_id' => $contact->id,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/customers/'.$customer->id.'/anonymize', [
            'reason' => 'Erasure request received by email on 21 September.',
            'confirmation' => true,
        ])
        ->assertRedirect();

    $customer = $customer->fresh();
    $contact = $contact->fresh();

    expect($customer->isAnonymized())->toBeTrue()
        ->and($customer->company_name)->toBe('Anonymised customer')
        ->and($customer->tax_id)->toBeNull()
        // The row survives, so the financial history that references it
        // stays intact.
        ->and($customer->exists)->toBeTrue()
        ->and($contact->email)->not->toBe('erase@example.test')
        ->and($contact->password)->toBeNull()
        ->and($contact->two_factor_secret)->toBeNull()
        ->and($contact->portal_access)->toBeFalse()
        ->and($contact->status)->toBe(AccountStatus::Closed)
        ->and(Address::query()->where('addressable_id', $contact->id)->count())->toBe(0);
});

it('leaves the audit trail intact after erasure', function (): void {
    $customer = Customer::factory()->create();

    $before = AuditLog::query()
        ->withoutGlobalScope('organization')->count();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/customers/'.$customer->id.'/anonymize', [
            'reason' => 'Erasure request received by email on 21 September.',
            'confirmation' => true,
        ]);

    $after = AuditLog::query()
        ->withoutGlobalScope('organization')->count();

    // The trail grew by the erasure record itself and lost nothing.
    expect($after)->toBeGreaterThan($before);
});

it('requires a stated reason before erasing anything', function (): void {
    $customer = Customer::factory()->create(['company_name' => 'Still Here']);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/customers/'.$customer->id.'/anonymize', ['confirmation' => true])
        ->assertSessionHasErrors('reason');

    expect($customer->fresh()->isAnonymized())->toBeFalse();
});

it('refuses to edit a customer after erasure', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($this->admin, 'staff')->post('/admin/customers/'.$customer->id.'/anonymize', [
        'reason' => 'Erasure request received by email on 21 September.',
        'confirmation' => true,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/customers/'.$customer->id, [
            'company_name' => 'Reintroduced',
            'status' => CustomerStatus::Closed->value,
            'currency_code' => 'EUR',
        ])
        ->assertForbidden();
});
