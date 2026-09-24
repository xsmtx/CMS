<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Api\WebhookEvent;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Api\Models\WebhookDelivery;
use App\Infrastructure\Api\Models\WebhookEndpoint;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Notifications\Models\InAppNotification;
use App\Infrastructure\Support\Models\Ticket;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The customer's own buttons, driven.
 *
 * The admin audit found that a screen whose actions no test performs has not
 * been tested; the same question asked of the client area turned up eighteen
 * endpoints nobody had ever called. These are the ones a customer presses, which
 * makes them worse to get wrong: an operator can ask a colleague whether a button
 * is meant to do nothing, and a customer cannot.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create();

    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();

    $this->member = Contact::factory()->forCustomer($this->customer)->create();
    $this->member->assignRole(SystemRole::PortalMember);
    $this->member = $this->member->fresh();
});

it('replies to its own ticket, and moves it to customer-reply', function (): void {
    $ticket = Ticket::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => TicketStatus::Answered->value,
    ]);

    $this->actingAs($this->owner, 'client')
        ->post('/client/support/'.$ticket->id.'/replies', [
            'body' => 'That did not work, the site is still down.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($ticket->replies()->count())->toBe(1)
        // `ReplyToTicket` decides which status a reply implies and asks
        // `TransitionTicket` for it — one clock, one place that moves it.
        ->and($ticket->fresh()->status)->toBe(TicketStatus::CustomerReply);
});

it('cannot reply to somebody else ticket', function (): void {
    $other = Customer::factory()->create();

    $theirs = Ticket::factory()->create([
        'organization_id' => $other->organization_id,
        'customer_id' => $other->id,
    ]);

    // 404 rather than 403: a refusal would confirm the ticket exists.
    $this->actingAs($this->owner, 'client')
        ->post('/client/support/'.$theirs->id.'/replies', ['body' => 'Hello?'])
        ->assertNotFound();

    expect($theirs->replies()->count())->toBe(0);
});

it('chooses a default payment method', function (): void {
    $first = PaymentMethod::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'is_default' => true,
    ]);

    $second = PaymentMethod::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'is_default' => false,
    ]);

    $this->actingAs($this->owner, 'client')
        ->put('/client/billing/methods/'.$second->id.'/default')
        ->assertRedirect();

    // Exactly one default: the old one has to be cleared, or a renewal has two
    // cards to choose between and picks by row order.
    expect($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();
});

it('turns auto-renew off and on for its own domain', function (): void {
    $domain = Domain::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'auto_renew' => true,
    ]);

    $this->actingAs($this->owner, 'client')
        ->put('/client/domains/'.$domain->id.'/auto-renew', ['auto_renew' => false])
        ->assertRedirect();

    expect($domain->fresh()->auto_renew)->toBeFalse();

    $this->actingAs($this->owner, 'client')
        ->put('/client/domains/'.$domain->id.'/auto-renew', ['auto_renew' => true])
        ->assertRedirect();

    expect($domain->fresh()->auto_renew)->toBeTrue();
});

it('saves its notification preferences and marks its notifications read', function (): void {
    $this->actingAs($this->owner, 'client')
        // The form posts the *category* names, which are not the column names:
        // `invoices`, not `notify_invoices`. A test that guessed the columns
        // would pass through `boolean()` as four falses and prove nothing.
        ->put('/client/notifications/preferences', [
            'invoices' => true,
            'support' => true,
            'product' => false,
            'marketing' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->owner->fresh()->notify_marketing)->toBeFalse()
        ->and($this->owner->fresh()->notify_invoices)->toBeTrue();

    // Notifiable rather than a contact column: a staff member and a contact
    // both receive these, and two nullable columns would be two chances to
    // write neither.
    $notification = InAppNotification::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'notifiable_type' => $this->owner->getMorphClass(),
        'notifiable_id' => $this->owner->id,
        'read_at' => null,
    ]);

    $this->actingAs($this->owner, 'client')
        ->post('/client/notifications/read')
        ->assertRedirect();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('registers a webhook endpoint, redelivers one and removes it', function (): void {
    $this->actingAs($this->owner, 'client')
        ->post('/client/developer/webhooks', [
            'url' => 'https://hooks.example.test/infracms',
            'description' => 'Our billing sync',
            'events' => [WebhookEvent::InvoiceCreated->value],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $endpoint = WebhookEndpoint::query()->where('description', 'Our billing sync')->first();

    expect($endpoint)->not->toBeNull()
        ->and($endpoint->customer_id)->toBe($this->customer->id)
        // A secret the customer never typed, so it can be long, and it is
        // encrypted at rest.
        ->and($endpoint->secret)->not->toBeEmpty()
        ->and($endpoint->getRawOriginal('secret'))->not->toBe($endpoint->secret);

    $delivery = WebhookDelivery::factory()->create([
        'organization_id' => $endpoint->organization_id,
        'endpoint_id' => $endpoint->id,
    ]);

    $this->actingAs($this->owner, 'client')
        ->post('/client/developer/webhooks/deliveries/'.$delivery->id.'/redeliver')
        ->assertRedirect();

    // A redelivery keeps the event id across attempts, which is what makes the
    // receiver able to deduplicate it (ADR 0034).
    expect($delivery->fresh()->event_id)->toBe($delivery->event_id);

    $this->actingAs($this->owner, 'client')
        ->delete('/client/developer/webhooks/'.$endpoint->id)
        ->assertRedirect();

    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->exists())->toBeFalse();
});

it('refuses a webhook endpoint that is not https', function (): void {
    $this->actingAs($this->owner, 'client')
        ->post('/client/developer/webhooks', ['url' => 'http://hooks.example.test/plain'])
        ->assertSessionHasErrors('url');

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('keeps the developer section away from a portal member', function (): void {
    $this->actingAs($this->member, 'client')
        ->post('/client/developer/webhooks', ['url' => 'https://hooks.example.test/x'])
        ->assertForbidden();

    expect(WebhookEndpoint::query()->count())->toBe(0);
});
