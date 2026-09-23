<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Support\OpenTicket;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Content\Models\Announcement;
use App\Infrastructure\Content\Models\KbArticle;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Support\Models\Department;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create(['company_name' => 'Northwind Supply']);
    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();

    $this->agent = StaffUser::factory()->create(['name' => 'Kerem']);
    $this->agent->assignRole(SystemRole::Support);
    $this->agent = $this->agent->fresh();

    $this->department = Department::factory()->sla(240, 1440)->create();
});

function ticketFor(Customer $customer, ?Contact $contact, ?Department $department): mixed
{
    return app(OpenTicket::class)->handle(
        $customer,
        $contact,
        $department,
        'Disk is full',
        'The server stopped accepting uploads.',
        TicketPriority::Normal,
    );
}

it('lists a customer their own tickets and nobody else’s', function (): void {
    $mine = ticketFor($this->customer, $this->owner, $this->department);

    $stranger = Customer::factory()->create();
    ticketFor($stranger, null, $this->department);

    $this->actingAs($this->owner, 'client')
        ->get('/client/support')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Support/Index')
            ->has('tickets', 1)
            ->where('tickets.0.id', $mine->id));
});

it('refuses a customer a ticket that is not theirs', function (): void {
    $stranger = ticketFor(Customer::factory()->create(), null, $this->department);

    $this->actingAs($this->owner, 'client')
        ->get('/client/support/'.$stranger->id)
        ->assertNotFound();
});

it('opens a ticket from the portal', function (): void {
    $this->actingAs($this->owner, 'client')
        ->post('/client/support', [
            'department_id' => $this->department->id,
            'subject' => 'Please move my DNS',
            'body' => 'The records are in the attached file.',
        ])
        ->assertRedirect();

    $this->actingAs($this->owner, 'client')
        ->get('/client/support')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('tickets.0.subject', 'Please move my DNS'));
});

it('shows staff the queue with the tickets waiting on them', function (): void {
    ticketFor($this->customer, $this->owner, $this->department);

    $this->actingAs($this->agent, 'staff')
        ->get('/admin/support')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Support/Index')
            ->has('tickets.data', 1));
});

it('answers a ticket from the admin side and stops the clock', function (): void {
    $ticket = ticketFor($this->customer, $this->owner, $this->department);

    $this->actingAs($this->agent, 'staff')
        ->post('/admin/support/'.$ticket->id.'/replies', [
            'body' => 'Cleared 12 GB of logs. You should be able to upload again.',
        ])
        ->assertRedirect();

    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatus::Answered)
        ->and($ticket->first_responded_at)->not->toBeNull();
});

it('refuses the support screens to staff without the permission', function (): void {
    // A staff account with no role at all: signed in, and still not
    // entitled to read somebody's support history.
    $outsider = StaffUser::factory()->create();

    $this->actingAs($outsider, 'staff')
        ->get('/admin/support')
        ->assertForbidden();
});

it('shows the content screens to an agent', function (): void {
    Announcement::factory()->create(['title' => 'Maintenance on Sunday']);
    KbArticle::factory()->create(['title' => 'Resetting your cPanel password']);

    $this->actingAs($this->agent, 'staff')
        ->get('/admin/content/announcements')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Content/Announcements')
            ->where('announcements.0.title', 'Maintenance on Sunday'));

    $this->actingAs($this->agent, 'staff')
        ->get('/admin/content/articles')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Content/Articles')
            ->where('articles.0.title', 'Resetting your cPanel password'));
});

it('shows the template editor and the delivery log', function (): void {
    $this->actingAs($this->agent, 'staff')
        ->get('/admin/notifications/templates')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Notifications/Templates')
            // Every event the platform can send has a row, customised or not.
            ->has('templates')
            ->where('can.manage', false));

    $this->actingAs($this->agent, 'staff')
        ->get('/admin/notifications/log')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Notifications/Log'));
});

it('lets a customer read their notifications and change what they receive', function (): void {
    $this->actingAs($this->owner, 'client')
        ->get('/client/notifications')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Notifications/Index')
            ->has('preferences'));
});
