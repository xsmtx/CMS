<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Identity\Impersonator;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\Impersonation;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

const REASON = 'Customer reported a billing discrepancy on ticket 4182.';

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()->withoutGlobalScope('organization')
        ->where('type', 'provider')->sole();

    $this->admin = StaffUser::factory()->forOrganization($this->provider)->create();
    $this->admin->assignRole(SystemRole::SuperAdmin);
    $this->admin = $this->admin->fresh();

    $this->customer = Customer::factory()->create();
    $this->contact = Contact::factory()->forCustomer($this->customer)->primary()->create();
});

it('swaps the staff session for the customer session', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/contacts/'.$this->contact->id.'/impersonate', ['reason' => REASON])
        ->assertRedirect('/client');

    // Only one guard is ever authenticated, so nothing downstream has to
    // decide which of two identities is the real one.
    expect(auth('client')->id())->toBe($this->contact->id)
        ->and(auth('staff')->check())->toBeFalse();
});

it('records who, whom and why', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/contacts/'.$this->contact->id.'/impersonate', ['reason' => REASON]);

    $record = Impersonation::query()->withoutGlobalScope('organization')->sole();

    expect($record->impersonator_id)->toBe($this->admin->id)
        ->and($record->subject_id)->toBe($this->contact->id)
        ->and($record->reason)->toBe(REASON)
        ->and($record->isActive())->toBeTrue();
});

it('audits both the start and the end', function (): void {
    $audit = $this->fakeAudit();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/contacts/'.$this->contact->id.'/impersonate', ['reason' => REASON]);

    $audit->assertRecorded(
        'identity.impersonation.started',
        fn ($entry): bool => $entry->reason === REASON,
    );

    $this->delete('/client/impersonation');

    $audit->assertRecorded('identity.impersonation.ended');
});

it('requires a stated reason', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/contacts/'.$this->contact->id.'/impersonate', ['reason' => 'nope'])
        ->assertSessionHasErrors('reason');

    expect(auth('client')->check())->toBeFalse();
});

it('refuses without the permission', function (): void {
    $support = StaffUser::factory()->forOrganization($this->provider)->create();
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->post('/admin/contacts/'.$this->contact->id.'/impersonate', ['reason' => REASON])
        ->assertForbidden();

    expect(auth('client')->check())->toBeFalse();
});

it('refuses across the organization boundary', function (): void {
    $otherReseller = Organization::factory()->reseller($this->provider)->create();

    $theirContact = app(OrganizationContext::class)->runAs($otherReseller->id, function () use ($otherReseller): Contact {
        $customer = Customer::factory()->forOrganization(
            Organization::factory()->customerOf($otherReseller)->create(),
        )->create();

        return Contact::factory()->forCustomer($customer)->create();
    });

    $reseller = Organization::factory()->reseller($this->provider)->create();
    $resellerAdmin = StaffUser::factory()->forOrganization($reseller)->create();
    $resellerAdmin->assignRole(SystemRole::SuperAdmin);

    $this->actingAs($resellerAdmin->fresh(), 'staff')
        ->post('/admin/contacts/'.$theirContact->id.'/impersonate', ['reason' => REASON])
        ->assertNotFound();

    expect(auth('client')->check())->toBeFalse();
});

it('refuses a contact who cannot sign in anyway', function (): void {
    $noAccess = Contact::factory()->forCustomer($this->customer)->withoutPortalAccess()->create();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/contacts/'.$noAccess->id.'/impersonate', ['reason' => REASON])
        ->assertForbidden();
});

it('refuses to impersonate a colleague in the same organization', function (): void {
    // A contact in the acting organization is not a customer, so the
    // boundary check requires strictly below rather than at or below.
    $ownCustomer = Customer::factory()->forOrganization($this->provider)->create();
    $colleague = Contact::factory()->forCustomer($ownCustomer)->create();

    expect(app(Impersonator::class)->canImpersonate($this->admin, $colleague))->toBeFalse();
});

it('restores the staff session when it stops', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/contacts/'.$this->contact->id.'/impersonate', ['reason' => REASON]);

    $this->delete('/client/impersonation')->assertRedirect('/admin');

    expect(auth('staff')->id())->toBe($this->admin->id)
        ->and(auth('client')->check())->toBeFalse()
        ->and(Impersonation::query()->withoutGlobalScope('organization')->sole()->isActive())
        ->toBeFalse();
});

it('blocks security-sensitive actions while impersonating', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/contacts/'.$this->contact->id.'/impersonate', ['reason' => REASON]);

    // Changing the customer's password while acting as them would let a
    // staff member lock the real owner out, and the trail would show the
    // customer doing it.
    $this->put('/security/password', [
        'current_password' => 'password',
        'password' => 'hijacked-password-1!',
        'password_confirmation' => 'hijacked-password-1!',
    ])->assertForbidden();

    $this->delete('/security/two-factor')->assertForbidden();
    $this->delete('/security/sessions')->assertForbidden();
});

it('shares the banner state with the client area', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/contacts/'.$this->contact->id.'/impersonate', ['reason' => REASON]);

    $this->get('/client')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('impersonation.active', true)
            ->where('impersonation.subjectName', $this->contact->displayName()));
});

it('leaves no impersonation banner on a normal session', function (): void {
    $this->actingAs($this->contact, 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('impersonation', null));
});
