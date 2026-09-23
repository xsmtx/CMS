<?php

declare(strict_types=1);

use App\Application\Support\OpenTicket;
use App\Application\Support\ReplyToTicket;
use App\Application\Support\TransitionTicket;
use App\Domain\Support\TicketPriority;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Notifications\ChannelRegistry;
use App\Infrastructure\Notifications\Channels\DatabaseChannel;
use App\Infrastructure\Notifications\Models\InAppNotification;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    // Only the in-app channel, so the tests exercise the clock rather than
    // a mail transport.
    $registry = new ChannelRegistry;
    $registry->register(new DatabaseChannel);
    $this->app->instance(ChannelRegistry::class, $registry);

    $this->customer = Customer::factory()->create();
    $this->contact = Contact::factory()->forCustomer($this->customer)->primary()->create();

    $this->agent = StaffUser::factory()->create(['name' => 'Kerem']);

    $this->department = Department::factory()->sla(240, 1440)->create();
});

function openTicket(
    Customer $customer,
    ?Contact $contact,
    ?Department $department,
    TicketPriority $priority = TicketPriority::Normal,
): Ticket {
    return app(OpenTicket::class)->handle(
        $customer,
        $contact,
        $department,
        'Site is down',
        'Nothing loads since this morning.',
        $priority,
    );
}

it('opens a ticket with its number, its first message and its clock', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);

    expect($ticket->number)->toStartWith('TKT-')
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->replies()->count())->toBe(1)
        ->and($ticket->first_response_due_at)->not->toBeNull()
        // Four hours, as the department states for normal priority.
        ->and($ticket->first_response_due_at?->diffInMinutes(CarbonImmutable::now()->addMinutes(240)))
        ->toBeLessThan(2);
});

it('scales the clock by priority', function (): void {
    $urgent = openTicket($this->customer, $this->contact, $this->department, TicketPriority::Urgent);

    // A quarter of four hours. An operator sets two numbers, not eight.
    expect($urgent->first_response_due_at?->diffInMinutes(CarbonImmutable::now()->addMinutes(60)))
        ->toBeLessThan(2);
});

it('gives a ticket no due dates when the department is not measured', function (): void {
    $department = Department::factory()->withoutSla()->create();

    $ticket = openTicket($this->customer, $this->contact, $department);

    // A real configuration, not an error. Screens read null as "not
    // measured" rather than as "overdue".
    expect($ticket->first_response_due_at)->toBeNull()
        ->and($ticket->hasBreachedFirstResponse())->toBeFalse();
});

it('stops the clock on a public staff reply', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);

    app(ReplyToTicket::class)->fromStaff($ticket, $this->agent, 'Looking into it now.');

    $ticket->refresh();

    expect($ticket->first_responded_at)->not->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::Answered)
        ->and($ticket->hasBreachedFirstResponse())->toBeFalse();
});

it('does not stop the clock on an internal note', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);

    app(ReplyToTicket::class)->fromStaff($ticket, $this->agent, 'Chasing the datacentre.', internal: true);

    $ticket->refresh();

    // An agent writing "chasing the datacentre, will update" has not
    // answered anybody.
    expect($ticket->first_responded_at)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::Open);
});

it('never tells the customer an internal note exists', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);

    app(ReplyToTicket::class)->fromStaff($ticket, $this->agent, 'Customer is being difficult.', internal: true);

    expect($ticket->publicReplies()->count())->toBe(1)
        ->and($ticket->replies()->count())->toBe(2)
        // And no notification went anywhere near them.
        ->and(InAppNotification::query()
            ->where('notifiable_id', $this->contact->id)
            ->count())->toBe(1);
});

it('stamps the first response once, not on every reply', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);

    app(ReplyToTicket::class)->fromStaff($ticket, $this->agent, 'First answer.');
    $first = $ticket->fresh()?->first_responded_at;

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

    app(ReplyToTicket::class)->fromStaff($ticket->fresh() ?? $ticket, $this->agent, 'Second answer.');

    // A first-response SLA measures the first response.
    expect($ticket->fresh()?->first_responded_at?->toIso8601String())
        ->toBe($first?->toIso8601String());

    CarbonImmutable::setTestNow();
});

it('counts a late answer as a breach', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(6));

    app(ReplyToTicket::class)->fromStaff($ticket, $this->agent, 'Sorry for the delay.');

    // The comparison is against when the answer came, not against now.
    expect($ticket->fresh()?->hasBreachedFirstResponse())->toBeTrue();

    CarbonImmutable::setTestNow();
});

it('puts a customer reply back in our queue', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);

    app(ReplyToTicket::class)->fromStaff($ticket, $this->agent, 'Is it back now?');
    app(ReplyToTicket::class)->fromCustomer($ticket->fresh() ?? $ticket, $this->contact, 'Still down.');

    expect($ticket->fresh()?->status)->toBe(TicketStatus::CustomerReply)
        ->and($ticket->fresh()?->status->needsUs())->toBeTrue();
});

it('reopens a closed ticket when the customer replies', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);

    app(TransitionTicket::class)->handle($ticket, TicketStatus::Closed, $this->agent);

    expect($ticket->fresh()?->resolved_at)->not->toBeNull();

    app(ReplyToTicket::class)->fromCustomer($ticket->fresh() ?? $ticket, $this->contact, 'It happened again.');

    // Better than making them start again and re-explain.
    expect($ticket->fresh()?->status)->toBe(TicketStatus::CustomerReply)
        ->and($ticket->fresh()?->resolved_at)->toBeNull();
});

it('finds the tickets whose promise has run out', function (): void {
    openTicket($this->customer, $this->contact, $this->department);

    $late = openTicket($this->customer, $this->contact, $this->department);
    $late->forceFill(['first_response_due_at' => CarbonImmutable::now()->subHour()])->save();

    $breaching = Ticket::query()->withoutGlobalScope('organization')->breachingSla()->get();

    expect($breaching)->toHaveCount(1)
        ->and($breaching->first()?->id)->toBe($late->id);
});

it('tells the customer their ticket arrived, and staff that it did', function (): void {
    openTicket($this->customer, $this->contact, $this->department);

    expect(InAppNotification::query()->where('notifiable_id', $this->contact->id)->count())->toBe(1)
        ->and(InAppNotification::query()->where('notifiable_id', $this->agent->id)->count())->toBe(1);
});

it('sends a staff reply to the customer and not to the author', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);

    InAppNotification::query()->delete();

    app(ReplyToTicket::class)->fromStaff($ticket, $this->agent, 'Fixed.');

    expect(InAppNotification::query()->where('notifiable_id', $this->contact->id)->count())->toBe(1)
        ->and(InAppNotification::query()->where('notifiable_id', $this->agent->id)->count())->toBe(0);
});

it('sends a customer reply to the assignee alone', function (): void {
    $ticket = openTicket($this->customer, $this->contact, $this->department);
    $other = StaffUser::factory()->create();

    $ticket->forceFill(['assigned_to' => $this->agent->id])->save();

    InAppNotification::query()->delete();

    app(ReplyToTicket::class)->fromCustomer($ticket->fresh() ?? $ticket, $this->contact, 'Any news?');

    // A team of eight does not need eight emails about a ticket one of
    // them owns.
    expect(InAppNotification::query()->where('notifiable_id', $this->agent->id)->count())->toBe(1)
        ->and(InAppNotification::query()->where('notifiable_id', $other->id)->count())->toBe(0);
});
