<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Api\DeliverWebhookNow;
use App\Application\Api\DispatchWebhooks;
use App\Application\Automation\RecordedRun;
use App\Application\Automation\Runs\RetryWebhookDeliveries;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Api\ApiScope;
use App\Domain\Api\DeliveryState;
use App\Domain\Api\WebhookEvent;
use App\Domain\Automation\AutomationTask;
use App\Infrastructure\Api\Models\WebhookDelivery;
use App\Infrastructure\Api\Models\WebhookEndpoint;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Support\Models\Department;
use App\Infrastructure\Support\Models\Ticket;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create();
    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();

    $this->department = Department::factory()->create();
});

/**
 * @param  list<ApiScope>  $scopes
 * @return array<string, string>
 */
function apiHeaders(Contact $contact, array $scopes, ?string $idempotencyKey = null): array
{
    $token = $contact->createToken(
        'Integration',
        array_map(static fn (ApiScope $scope): string => $scope->value, $scopes),
    )->plainTextToken;

    return array_filter([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
        'Idempotency-Key' => $idempotencyKey,
    ]);
}

it('creates once however many times the same key is sent', function (): void {
    $headers = apiHeaders($this->owner, [ApiScope::TicketsWrite], 'key-one');

    $payload = [
        'department_id' => $this->department->id,
        'subject' => 'Disk is full',
        'body' => 'Uploads stopped.',
    ];

    $first = $this->postJson('/api/v1/tickets', $payload, $headers)->assertStatus(201);
    $second = $this->postJson('/api/v1/tickets', $payload, $headers)->assertStatus(201);

    // The same answer, not a second ticket. A client that did not hear the
    // first response is entitled to the first response.
    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Ticket::query()->withoutGlobalScope('organization')->count())->toBe(1)
        ->and($second->headers->get('Idempotent-Replay'))->toBe('true');
});

it('refuses the same key with a different payload', function (): void {
    $headers = apiHeaders($this->owner, [ApiScope::TicketsWrite], 'key-two');

    $this->postJson('/api/v1/tickets', [
        'department_id' => $this->department->id,
        'subject' => 'First',
        'body' => 'One.',
    ], $headers)->assertStatus(201);

    // A key reused across two different writes is a bug in the client, and
    // returning the first answer would hide it behind a correct-looking
    // response.
    $this->postJson('/api/v1/tickets', [
        'department_id' => $this->department->id,
        'subject' => 'Second',
        'body' => 'Two.',
    ], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_conflict');
});

it('does not burn the key when the write failed', function (): void {
    $headers = apiHeaders($this->owner, [ApiScope::TicketsWrite], 'key-three');

    // Invalid: no subject.
    $this->postJson('/api/v1/tickets', [
        'department_id' => $this->department->id,
        'body' => 'No subject.',
    ], $headers)->assertStatus(422);

    // The client fixed its payload. It must be able to retry — with the
    // same key, which is the whole point of having one.
    $this->postJson('/api/v1/tickets', [
        'department_id' => $this->department->id,
        'subject' => 'Now with a subject',
        'body' => 'Fixed.',
    ], $headers)->assertStatus(201);
});

it('writes twice without a key, which is the caller’s choice', function (): void {
    $headers = apiHeaders($this->owner, [ApiScope::TicketsWrite]);

    $payload = [
        'department_id' => $this->department->id,
        'subject' => 'Same subject',
        'body' => 'Same body.',
    ];

    $this->postJson('/api/v1/tickets', $payload, $headers)->assertStatus(201);
    $this->postJson('/api/v1/tickets', $payload, $headers)->assertStatus(201);

    // Two identical tickets is a real thing a customer might want, so the
    // header is offered rather than demanded.
    expect(Ticket::query()->withoutGlobalScope('organization')->count())->toBe(2);
});

it('registers an endpoint and shows its secret exactly once', function (): void {
    $headers = apiHeaders($this->owner, [ApiScope::WebhooksWrite, ApiScope::WebhooksRead]);

    $created = $this->postJson('/api/v1/webhooks', [
        'url' => 'https://example.test/hooks/billing',
        'description' => 'Billing system',
        'events' => [WebhookEvent::InvoicePaid->value],
    ], $headers)->assertStatus(201);

    expect($created->json('data.secret'))->toBeString()->not->toBeEmpty();

    $listed = $this->getJson('/api/v1/webhooks', $headers)->assertOk();

    // Never readable again: anybody who can read it can forge an event
    // from us.
    expect($listed->json('data.endpoints.0'))->not->toHaveKey('secret');
});

it('refuses an endpoint that is not https', function (): void {
    $headers = apiHeaders($this->owner, [ApiScope::WebhooksWrite]);

    $this->postJson('/api/v1/webhooks', [
        'url' => 'http://example.test/hooks',
    ], $headers)->assertStatus(422);
});

it('posts a signed payload a receiver can verify', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $endpoint = WebhookEndpoint::factory()->forCustomer($this->customer)->create([
        'secret' => 'shhh-this-is-the-secret',
    ]);

    $deliveries = app(DispatchWebhooks::class)->handle(
        WebhookEvent::InvoicePaid,
        $this->customer->organization_id,
        ['number' => 'INV-000001'],
        $this->customer->id,
    );

    expect($deliveries)->toHaveCount(1);

    app(DeliverWebhookNow::class)->handle($deliveries[0]);

    Http::assertSent(function ($request) use ($endpoint): bool {
        $timestamp = $request->header('X-InfraCMS-Timestamp')[0];
        $signature = $request->header('X-InfraCMS-Signature')[0];

        // Over the exact bytes, which is the only signature a receiver can
        // reproduce without guessing how we encode JSON.
        return hash_equals(
            hash_hmac('sha256', $timestamp.'.'.$request->body(), $endpoint->secret),
            $signature,
        );
    });

    expect($deliveries[0]->fresh()?->status)->toBe(DeliveryState::Delivered);
});

it('carries a stable event id across endpoints and attempts', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    WebhookEndpoint::factory()->forCustomer($this->customer)->count(2)->create();

    $deliveries = app(DispatchWebhooks::class)->handle(
        WebhookEvent::InvoicePaid,
        $this->customer->organization_id,
        ['number' => 'INV-000001'],
        $this->customer->id,
    );

    // One event, two endpoints, one id — so a receiver that hears about it
    // twice can tell it is the same fact.
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[0]->event_id)->toBe($deliveries[1]->event_id);

    $before = $deliveries[0]->event_id;

    app(DeliverWebhookNow::class)->handle($deliveries[0]);

    expect($deliveries[0]->fresh()?->event_id)->toBe($before);
});

it('retries a failed delivery with a growing gap and then gives up', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    config(['platform.api.webhooks.max_attempts' => 2]);

    $delivery = WebhookDelivery::factory()->create([
        'organization_id' => $this->customer->organization_id,
    ]);

    app(DeliverWebhookNow::class)->handle($delivery);

    expect($delivery->fresh()?->status)->toBe(DeliveryState::Retrying)
        ->and($delivery->fresh()?->next_attempt_at)->not->toBeNull();

    app(DeliverWebhookNow::class)->handle($delivery->fresh());

    expect($delivery->fresh()?->status)->toBe(DeliveryState::Failed)
        ->and($delivery->fresh()?->next_attempt_at)->toBeNull();
});

it('never follows a redirect to somewhere the operator did not configure', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test/'])]);

    $delivery = WebhookDelivery::factory()->create([
        'organization_id' => $this->customer->organization_id,
    ]);

    app(DeliverWebhookNow::class)->handle($delivery);

    // A 3xx is a failure, not a hop: following it would post a signed
    // customer payload wherever the redirect points.
    expect($delivery->fresh()?->status)->not->toBe(DeliveryState::Delivered)
        ->and($delivery->fresh()?->response_status)->toBe(302);
});

it('switches off an endpoint that keeps failing', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    config([
        'platform.api.webhooks.max_attempts' => 1,
        'platform.api.webhooks.disable_after_failures' => 1,
    ]);

    $endpoint = WebhookEndpoint::factory()->forCustomer($this->customer)->create();

    $delivery = WebhookDelivery::factory()->create([
        'endpoint_id' => $endpoint->id,
        'organization_id' => $endpoint->organization_id,
    ]);

    app(DeliverWebhookNow::class)->handle($delivery);

    // Disabled, not deleted: the operator's configuration survives so they
    // can fix the URL and switch it back on.
    expect($endpoint->fresh()?->disabled_at)->not->toBeNull();
});

it('re-queues deliveries whose next attempt is due', function (): void {
    Queue::fake();

    WebhookDelivery::factory()->dueForRetry()->create([
        'organization_id' => $this->customer->organization_id,
    ]);

    $record = app(RecordedRun::class)->handle(
        AutomationTask::Webhooks,
        app(RetryWebhookDeliveries::class),
    );

    expect($record->changed)->toBe(1);
});

it('lets a customer send a delivery again, with the payload it had', function (): void {
    Queue::fake();

    $endpoint = WebhookEndpoint::factory()->forCustomer($this->customer)->create();

    $delivery = WebhookDelivery::factory()->create([
        'endpoint_id' => $endpoint->id,
        'organization_id' => $endpoint->organization_id,
        'payload' => ['data' => ['number' => 'INV-000001']],
        'status' => DeliveryState::Failed->value,
    ]);

    $headers = apiHeaders($this->owner, [ApiScope::WebhooksWrite]);

    $this->postJson('/api/v1/webhooks/deliveries/'.$delivery->id.'/redeliver', [], $headers)
        ->assertStatus(202);

    // The stored payload, not a rebuilt one: an event is a statement about
    // a moment.
    expect($delivery->fresh()?->payload)->toBe(['data' => ['number' => 'INV-000001']]);
});

it('refuses to redeliver somebody else’s delivery', function (): void {
    $stranger = WebhookDelivery::factory()->create();

    $headers = apiHeaders($this->owner, [ApiScope::WebhooksWrite]);

    $this->postJson('/api/v1/webhooks/deliveries/'.$stranger->id.'/redeliver', [], $headers)
        ->assertStatus(404);
});

it('posts only to endpoints that asked for the event', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    WebhookEndpoint::factory()
        ->forCustomer($this->customer)
        ->subscribedTo([WebhookEvent::TicketCreated])
        ->create();

    $deliveries = app(DispatchWebhooks::class)->handle(
        WebhookEvent::InvoicePaid,
        $this->customer->organization_id,
        ['number' => 'INV-000001'],
        $this->customer->id,
    );

    expect($deliveries)->toBe([]);
});

it('posts everything to an endpoint that chose nothing', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    WebhookEndpoint::factory()->forCustomer($this->customer)->create(['events' => []]);

    // Empty means all: an endpoint that silently receives nothing because
    // nobody ticked a box is a worse first experience than one that
    // receives too much.
    expect(app(DispatchWebhooks::class)->handle(
        WebhookEvent::InvoicePaid,
        $this->customer->organization_id,
        ['number' => 'INV-000001'],
        $this->customer->id,
    ))->toHaveCount(1);
});

it('never posts one customer’s event to another customer’s endpoint', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $stranger = Customer::factory()->create();
    WebhookEndpoint::factory()->forCustomer($stranger)->create();

    expect(app(DispatchWebhooks::class)->handle(
        WebhookEvent::InvoicePaid,
        $this->customer->organization_id,
        ['number' => 'INV-000001'],
        $this->customer->id,
    ))->toBe([]);
});
