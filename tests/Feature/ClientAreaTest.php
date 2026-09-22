<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create(['company_name' => 'Northwind Supply']);

    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create([
        'first_name' => 'Ines',
        'last_name' => 'Caetano',
    ]);
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();

    $this->member = Contact::factory()->forCustomer($this->customer)->create();
    $this->member->assignRole(SystemRole::PortalMember);
    $this->member = $this->member->fresh();
});

it('shows a contact their own profile', function (): void {
    $this->actingAs($this->owner, 'client')
        ->get('/client/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Profile')
            ->where('customer.companyName', 'Northwind Supply')
            ->where('me.firstName', 'Ines')
            ->where('can.manage', true));
});

it('lets a contact correct their own details', function (): void {
    $this->actingAs($this->member, 'client')
        ->put('/client/profile', [
            'first_name' => 'Corrected',
            'last_name' => 'Name',
            'phone' => '+31201234567',
            'notify_marketing' => false,
        ])
        ->assertRedirect();

    expect($this->member->fresh()->first_name)->toBe('Corrected');
});

it('lets the account owner change the company details', function (): void {
    $this->actingAs($this->owner, 'client')
        ->put('/client/profile/customer', [
            'company_name' => 'Northwind Supply BV',
            'legal_name' => 'Northwind Supply B.V.',
            'tax_id' => 'NL123456789B01',
        ])
        ->assertRedirect();

    expect($this->customer->fresh()->company_name)->toBe('Northwind Supply BV');
});

it('refuses a portal member changing the company details', function (): void {
    $this->actingAs($this->member, 'client')
        ->put('/client/profile/customer', ['company_name' => 'Hijacked'])
        ->assertForbidden();

    expect($this->customer->fresh()->company_name)->toBe('Northwind Supply');
});

it('lists the people on the account', function (): void {
    $this->actingAs($this->owner, 'client')
        ->get('/client/contacts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Contacts')
            ->has('contacts', 2)
            ->where('can.manage', true));
});

it('lets the account owner add a contact', function (): void {
    $this->actingAs($this->owner, 'client')
        ->post('/client/contacts', [
            'first_name' => 'New',
            'last_name' => 'Colleague',
            'email' => 'colleague@example.test',
            'portal_access' => true,
        ])
        ->assertRedirect();

    $added = Contact::query()->where('email', 'colleague@example.test')->sole();

    expect($added->customer_id)->toBe($this->customer->id)
        // Added contacts are never primary from this screen: primacy decides
        // where invoices go, so it stays the provider's to set.
        ->and($added->is_primary)->toBeFalse()
        ->and($added->hasRole(SystemRole::PortalMember))->toBeTrue();
});

it('refuses a portal member adding a contact', function (): void {
    // A self-amending access list is exactly the escalation nobody notices.
    $this->actingAs($this->member, 'client')
        ->post('/client/contacts', [
            'first_name' => 'Smuggled',
            'last_name' => 'In',
            'email' => 'smuggled@example.test',
            'portal_access' => true,
        ])
        ->assertForbidden();

    expect(Contact::query()->where('email', 'smuggled@example.test')->exists())->toBeFalse();
});

it('refuses to remove the account owner or yourself', function (): void {
    $this->actingAs($this->owner, 'client')
        ->delete('/client/contacts/'.$this->owner->id)
        ->assertForbidden();

    expect(Contact::query()->find($this->owner->id))->not->toBeNull();
});

it('removes another contact', function (): void {
    $this->actingAs($this->owner, 'client')
        ->delete('/client/contacts/'.$this->member->id)
        ->assertRedirect();

    expect(Contact::query()->find($this->member->id))->toBeNull();
});

it('never reaches a contact belonging to another customer', function (): void {
    $otherCustomer = Customer::factory()->create();
    $theirs = Contact::factory()->forCustomer($otherCustomer)->create();

    $this->actingAs($this->owner, 'client')
        ->delete('/client/contacts/'.$theirs->id)
        ->assertNotFound();

    expect(Contact::query()->withoutGlobalScope('organization')->find($theirs->id))->not->toBeNull();
});

it('withdraws the role along with portal access', function (): void {
    $this->actingAs($this->owner, 'client')
        ->put('/client/contacts/'.$this->member->id, [
            'first_name' => $this->member->first_name,
            'last_name' => $this->member->last_name,
            'email' => $this->member->email,
            'portal_access' => false,
        ])
        ->assertRedirect();

    $member = $this->member->fresh();

    expect($member->portal_access)->toBeFalse()
        ->and($member->password)->toBeNull()
        ->and($member->roles()->count())->toBe(0);
});
