<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Domains\DomainStatus;
use App\Domain\Identity\AccountStatus;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Notifications\Models\NotificationTemplate;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The last of the admin writes nothing had ever driven.
 *
 * Contacts, the two status transitions, the notification templates and the
 * staff account's own security controls. Grouped in one file because each is a
 * handful of endpoints rather than a screen's worth, and split from the others
 * only so a failure names the area it is in.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->staff = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->staff->assignRole(SystemRole::Administrator);
    $this->staff = $this->staff->fresh();

    $this->customer = Customer::factory()->create();
});

it('adds, edits and removes a contact on a customer', function (): void {
    $payload = [
        'first_name' => 'Zeynep',
        'last_name' => 'Kaya',
        'email' => 'zeynep@example.test',
        'phone' => '',
        'portal_access' => true,
        'is_primary' => false,
        'status' => AccountStatus::Active->value,
        'notify_invoices' => true,
        'notify_support' => true,
        'notify_product' => false,
        'notify_marketing' => false,
    ];

    $this->actingAs($this->staff, 'staff')
        ->post('/admin/customers/'.$this->customer->id.'/contacts', $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $contact = Contact::query()->where('email', 'zeynep@example.test')->first();

    expect($contact)->not->toBeNull()
        ->and($contact->customer_id)->toBe($this->customer->id)
        ->and($contact->phone)->toBeNull();

    // Saving the same contact again is the case a uniqueness rule that forgot
    // to ignore the current row would refuse — and the address is the sign-in
    // identifier, so that rule has to be there.
    $this->actingAs($this->staff, 'staff')
        ->put('/admin/customers/'.$this->customer->id.'/contacts/'.$contact->id, [
            ...$payload,
            'last_name' => 'Kaya-Demir',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($contact->fresh()->last_name)->toBe('Kaya-Demir');

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/customers/'.$this->customer->id.'/contacts/'.$contact->id)
        ->assertRedirect();

    expect(Contact::query()->whereKey($contact->id)->exists())->toBeFalse();
});

it('refuses a contact whose address belongs to somebody else', function (): void {
    $existing = Contact::factory()->create([
        'customer_id' => $this->customer->id,
        'email' => 'taken@example.test',
    ]);

    $this->actingAs($this->staff, 'staff')
        ->post('/admin/customers/'.$this->customer->id.'/contacts', [
            'first_name' => 'Ali',
            'last_name' => 'Yilmaz',
            'email' => $existing->email,
            'status' => AccountStatus::Active->value,
        ])
        ->assertSessionHasErrors('email');

    expect(Contact::query()->where('email', 'taken@example.test')->count())->toBe(1);
});

it('refuses a contact reached through the wrong customer', function (): void {
    $other = Customer::factory()->create();
    $contact = Contact::factory()->create(['customer_id' => $this->customer->id]);

    /*
     * 403 with a sentence, and here that is right where the client area's rule
     * is the opposite.
     *
     * A customer who asks for a record that is not theirs gets 404, because a
     * 403 would confirm it exists. A staff member inside the boundary can
     * already list both customers and both contacts, so nothing is confirmed by
     * refusing them — and "that contact is not on this customer" is the only
     * answer that tells them what they actually did wrong.
     */
    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/customers/'.$other->id.'/contacts/'.$contact->id)
        ->assertForbidden();

    expect(Contact::query()->whereKey($contact->id)->exists())->toBeTrue();
});

it('moves a service to another status from the screen', function (): void {
    $service = Service::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Active->value,
    ]);

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/services/'.$service->id.'/status', [
            'status' => ServiceStatus::Suspended->value,
            'reason' => 'Non-payment',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($service->fresh()->status)->toBe(ServiceStatus::Suspended);
});

it('moves a domain to another status from the screen', function (): void {
    $domain = Domain::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => DomainStatus::Active->value,
    ]);

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/domains/'.$domain->id.'/status', [
            'status' => DomainStatus::Expired->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($domain->fresh()->status)->toBe(DomainStatus::Expired);
});

it('customises a notification template, sends itself a test and resets it', function (): void {
    $event = NotificationEvent::InvoiceIssued;

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/notifications/templates/'.$event->value.'/en', [
            'subject' => 'Your invoice :number is ready',
            'body' => 'Hello :name, invoice :number is ready.',
            'action_label' => 'View invoice',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $template = NotificationTemplate::query()
        ->where('event', $event->value)
        ->where('locale', 'en')
        ->first();

    expect($template)->not->toBeNull()
        ->and($template->is_customised)->toBeTrue();

    $this->actingAs($this->staff, 'staff')
        ->post('/admin/notifications/templates/'.$event->value.'/en/test')
        ->assertRedirect();

    $this->actingAs($this->staff, 'staff')
        ->delete('/admin/notifications/templates/'.$template->id)
        ->assertRedirect();

    // Deleted rather than overwritten: the shipped wording lives in `lang/` and
    // goes on being translated, which a copied-in string would not.
    expect(NotificationTemplate::query()->whereKey($template->id)->exists())->toBeFalse();
});

it('reassigns a ticket and moves its status in one save', function (): void {
    $department = Department::factory()
        ->create(['organization_id' => $this->provider->id]);

    $ticket = Ticket::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => TicketStatus::Open->value,
    ]);

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/support/'.$ticket->id, [
            'department_id' => $department->id,
            'assigned_to' => $this->staff->id,
            'priority' => TicketPriority::High->value,
            'status' => TicketStatus::Answered->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $ticket = $ticket->fresh();

    expect($ticket->department_id)->toBe($department->id)
        ->and($ticket->assigned_to)->toBe($this->staff->id)
        ->and($ticket->priority)->toBe(TicketPriority::High)
        // The status went through `TransitionTicket`, which is the one place
        // that moves it and the clock with it (ADR 0030).
        ->and($ticket->status)->toBe(TicketStatus::Answered);
});

it('leaves a ticket alone when the form names nothing', function (): void {
    $ticket = Ticket::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => TicketStatus::Open->value,
    ]);

    $this->actingAs($this->staff, 'staff')
        ->put('/admin/support/'.$ticket->id, [])
        ->assertRedirect();

    // An empty save is a no-op rather than a ticket with its fields cleared.
    expect($ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->fresh()->assigned_to)->toBeNull();
});

it('regenerates its own recovery codes', function (): void {
    $staff = StaffUser::factory()->create([
        'organization_id' => $this->provider->id,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['one', 'two'],
        'two_factor_confirmed_at' => now(),
    ]);

    $staff->assignRole(SystemRole::Administrator);

    $this->actingAs($staff->fresh(), 'staff')
        ->post('/admin/security/two-factor/recovery-codes')
        ->assertRedirect();

    $codes = $staff->fresh()->two_factor_recovery_codes;

    expect($codes)->not->toBe(['one', 'two'])
        ->and($codes)->toBeArray()
        ->and(count($codes))->toBeGreaterThan(1);
});
