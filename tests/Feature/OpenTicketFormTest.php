<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Support\TicketMarkdown;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * The desk opening a ticket while the customer is still on the phone.
 *
 * Everything here is about what the operator has in front of them at that
 * moment: who the client is, what they own, who else should see the
 * thread, and whether the customer should be emailed about a call that
 * has already been answered.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->customer = Customer::factory()->create();
    $this->contact = Contact::factory()->forCustomer($this->customer)->primary()->create();
});

it('shows what the client owns once one is chosen', function (): void {
    Service::factory()->count(2)->create(['customer_id' => $this->customer->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/support/create?customer='.$this->customer->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Support/Create')
            ->where('chosen.id', $this->customer->id)
            ->has('owned', 2)
            ->has('contacts', 1)
            ->has('articles')
            ->has('canned'));
});

it('shows nobody and nothing before a client is chosen', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/support/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('chosen', null)
            ->has('owned', 0)
            ->has('candidates', 0));
});

it('keeps the copied-in addresses on the ticket', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/support', [
            'customer_id' => $this->customer->id,
            'subject' => 'Disk is full',
            'body' => 'The **site** is down.',
            'cc' => ['Dev@Example.Test', 'dev@example.test', 'accounts@example.test'],
        ])
        ->assertRedirect();

    $ticket = Ticket::query()->orderByDesc('id')->firstOrFail();

    // Lower-cased and de-duplicated: the same address typed twice in two
    // cases is one person, and mailing them twice is how a thread starts
    // looking broken.
    expect($ticket->cc_recipients)->toBe(['dev@example.test', 'accounts@example.test']);
});

it('writes the ticket without the email when the desk says not to send one', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/support', [
            'customer_id' => $this->customer->id,
            'subject' => 'Called about billing',
            'body' => 'Answered on the phone.',
            'send_email' => false,
        ])
        ->assertRedirect();

    expect(Ticket::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()
            ->where('event', NotificationEvent::TicketOpened->value)
            ->count())->toBe(0);
});

it('links the ticket only to something the client actually owns', function (): void {
    $mine = Service::factory()->create(['customer_id' => $this->customer->id]);
    $theirs = Service::factory()->create();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/support', [
            'customer_id' => $this->customer->id,
            'subject' => 'Mine',
            'body' => 'About this one.',
            'service_id' => $mine->id,
        ])
        ->assertRedirect();

    expect(Ticket::query()->orderByDesc('id')->firstOrFail()->service_id)->toBe($mine->id);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/support', [
            'customer_id' => $this->customer->id,
            'subject' => 'Not mine',
            'body' => 'About somebody else.',
            'service_id' => $theirs->id,
        ])
        ->assertRedirect();

    // An id typed into a form is not proof of anything: the link is
    // dropped rather than obeyed.
    expect(Ticket::query()->orderByDesc('id')->firstOrFail()->service_id)->toBeNull();
});

it('keeps the files the operator attached to the opening message', function (): void {
    Storage::fake(config('platform.support.attachments.disk', 'local'));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/support', [
            'customer_id' => $this->customer->id,
            'subject' => 'With evidence',
            'body' => 'See attached.',
            'attachments' => [
                UploadedFile::fake()->create('error.txt', 4, 'text/plain'),
                UploadedFile::fake()->create('trace.txt', 6, 'text/plain'),
            ],
        ])
        ->assertRedirect();

    $ticket = Ticket::query()->orderByDesc('id')->firstOrFail();

    expect($ticket->attachments()->count())->toBe(2)
        ->and($ticket->attachments()->pluck('reply_id')->filter()->count())->toBe(2);
});

it('refuses an address that is not one', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/support', [
            'customer_id' => $this->customer->id,
            'subject' => 'Bad cc',
            'body' => 'Body.',
            'cc' => ['not-an-address'],
        ])
        ->assertSessionHasErrors('cc.0');
});

it('opens a ticket in the department the desk chose', function (): void {
    $department = Department::factory()->create();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/support', [
            'customer_id' => $this->customer->id,
            'department_id' => $department->id,
            'subject' => 'Routed',
            'body' => 'Body.',
            'priority' => 'high',
        ])
        ->assertRedirect();

    $ticket = Ticket::query()->orderByDesc('id')->firstOrFail();

    expect($ticket->department_id)->toBe($department->id)
        ->and($ticket->priority->value)->toBe('high');
});

/**
 * A support inbox is the most attractive place in a hosting platform to
 * put a script tag: anybody can open a ticket and an operator will read
 * it. So author HTML is removed rather than escaped — one config change
 * away from "rendered" is not a place to leave it.
 */
it('renders a body as markdown and strips whatever else was in it', function (): void {
    $html = app(TicketMarkdown::class)->toHtml(
        "Hello **there**\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))",
    );

    expect($html)->toContain('<strong>there</strong>')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('javascript:');
});

it('shows the rendered body on the thread', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/support', [
            'customer_id' => $this->customer->id,
            'subject' => 'Formatted',
            'body' => "- one\n- two",
        ])
        ->assertRedirect();

    $ticket = Ticket::query()->orderByDesc('id')->firstOrFail();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/support/'.$ticket->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('ticket.replies.0.html', fn (string $html): bool => str_contains($html, '<li>one</li>')));
});
