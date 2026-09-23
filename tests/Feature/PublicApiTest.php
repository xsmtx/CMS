<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Api\ApiScope;
use App\Infrastructure\Api\Models\ApiRequestRecord;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Support\Models\Department;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create(['company_name' => 'Northwind Supply']);

    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();
});

/**
 * @param  list<ApiScope>  $scopes
 */
function tokenFor(Contact $contact, array $scopes): string
{
    return $contact->createToken(
        'Integration',
        array_map(static fn (ApiScope $scope): string => $scope->value, $scopes),
    )->plainTextToken;
}

/**
 * @return array<string, string>
 */
function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

it('refuses a request with no token', function (): void {
    $this->getJson('/api/v1/profile')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('refuses a token that was never issued', function (): void {
    $this->getJson('/api/v1/profile', bearer('1|nonsense'))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('refuses a token whose holder lost portal access', function (): void {
    $token = tokenFor($this->owner, [ApiScope::ProfileRead]);

    $this->owner->forceFill(['portal_access' => false])->save();

    // Revoking somebody's access has to revoke their token with it, or a
    // token outlives the employment that justified it.
    $this->getJson('/api/v1/profile', bearer($token))->assertStatus(401);
});

it('answers who the token belongs to', function (): void {
    $token = tokenFor($this->owner, [ApiScope::ProfileRead]);

    $this->getJson('/api/v1/profile', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.id', $this->owner->id)
        ->assertJsonPath('data.customer.name', 'Northwind Supply');
});

it('refuses a scope the token does not carry', function (): void {
    $token = tokenFor($this->owner, [ApiScope::ProfileRead]);

    $this->getJson('/api/v1/services', bearer($token))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('refuses a scope whose permission the holder does not have', function (): void {
    // A member has no `portal.services.view`, so the scope means nothing
    // however loudly the token asks for it. A scope narrows; it never
    // grants.
    $member = Contact::factory()->forCustomer($this->customer)->create();
    $member->assignRole(SystemRole::PortalMember);

    $token = tokenFor($member->fresh(), [ApiScope::ServicesRead]);

    $this->getJson('/api/v1/services', bearer($token))->assertStatus(403);
});

it('does not let a read scope write', function (): void {
    $service = Service::factory()->forCustomer($this->customer)->create();

    $token = tokenFor($this->owner, [ApiScope::ServicesRead]);

    $this->postJson(
        '/api/v1/services/'.$service->id.'/actions/suspend',
        [],
        bearer($token),
    )->assertStatus(403);
});

it('lists only the services the token owner owns', function (): void {
    $mine = Service::factory()->forCustomer($this->customer)->create();
    Service::factory()->create();

    $token = tokenFor($this->owner, [ApiScope::ServicesRead]);

    $this->getJson('/api/v1/services', bearer($token))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

it('answers 404 for somebody else’s record rather than 403', function (): void {
    $stranger = Service::factory()->create();

    $token = tokenFor($this->owner, [ApiScope::ServicesRead]);

    // A 403 would confirm the id exists.
    $this->getJson('/api/v1/services/'.$stranger->id, bearer($token))
        ->assertStatus(404);
});

it('renders money as minor units and a currency, never a float', function (): void {
    Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'organization_id' => $this->customer->organization_id,
        'currency_code' => 'EUR',
        'subtotal_minor' => 1499,
        'total_minor' => 1499,
    ]);

    $token = tokenFor($this->owner, [ApiScope::InvoicesRead]);

    $response = $this->getJson('/api/v1/invoices', bearer($token))->assertOk();

    expect($response->json('data.0.total'))->toBe(['amount' => 1499, 'currency' => 'EUR']);

    // Nothing anywhere in the body is a float. A client that received
    // 14.99 would have to guess whether a cent had already been lost.
    $floats = [];
    $body = $response->json();
    array_walk_recursive($body, static function (mixed $value) use (&$floats): void {
        if (is_float($value)) {
            $floats[] = $value;
        }
    });

    expect($floats)->toBe([]);
});

it('refuses a filter nobody declared rather than ignoring it', function (): void {
    $token = tokenFor($this->owner, [ApiScope::ServicesRead]);

    // An integrator whose typo is silently ignored gets a wrong list and
    // no way to discover why.
    $this->getJson('/api/v1/services?colour=blue', bearer($token))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a sort nobody declared', function (): void {
    $token = tokenFor($this->owner, [ApiScope::ServicesRead]);

    $this->getJson('/api/v1/services?sort=nmae', bearer($token))
        ->assertStatus(422);
});

it('filters and sorts on the declared fields', function (): void {
    Service::factory()->forCustomer($this->customer)->create([
        'status' => 'active',
        'name' => 'Alpha',
    ]);
    Service::factory()->forCustomer($this->customer)->create([
        'status' => 'suspended',
        'name' => 'Beta',
    ]);

    $token = tokenFor($this->owner, [ApiScope::ServicesRead]);

    $this->getJson('/api/v1/services?status=active&sort=name', bearer($token))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alpha');
});

it('paginates with a stable shape', function (): void {
    Service::factory()->count(3)->forCustomer($this->customer)->create();

    $token = tokenFor($this->owner, [ApiScope::ServicesRead]);

    $this->getJson('/api/v1/services?per_page=2', bearer($token))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'last_page'], 'links']);
});

it('caps an outrageous page size rather than refusing it', function (): void {
    Service::factory()->forCustomer($this->customer)->create();

    $token = tokenFor($this->owner, [ApiScope::ServicesRead]);

    $this->getJson('/api/v1/services?per_page=100000', bearer($token))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

it('opens a ticket through the same use case the portal uses', function (): void {
    $department = Department::factory()->create();

    $token = tokenFor($this->owner, [ApiScope::TicketsWrite]);

    $this->postJson('/api/v1/tickets', [
        'department_id' => $department->id,
        'subject' => 'Disk is full',
        'body' => 'Uploads stopped this morning.',
    ], bearer($token))
        ->assertStatus(201)
        ->assertJsonPath('data.subject', 'Disk is full')
        // The SLA clock is running, which is what proves it went through
        // `OpenTicket` rather than a second implementation.
        ->assertJsonPath('data.status', 'open');
});

it('records every request without recording any body', function (): void {
    $token = tokenFor($this->owner, [ApiScope::ProfileRead]);

    $this->getJson('/api/v1/profile', bearer($token))->assertOk();

    $record = ApiRequestRecord::query()->withoutGlobalScope('organization')->sole();

    expect($record->route)->toBe('api.v1.profile')
        ->and($record->status)->toBe(200)
        ->and($record->token_name)->toBe('Integration')
        // There is nowhere for a body to go, which is the point.
        ->and($record->getAttributes())->not->toHaveKey('body');
});

it('records a refused request too', function (): void {
    $this->getJson('/api/v1/profile')->assertStatus(401);

    $record = ApiRequestRecord::query()->withoutGlobalScope('organization')->sole();

    // The refused ones are the ones worth having.
    expect($record->status)->toBe(401)
        ->and($record->error_code)->toBe('unauthenticated');
});

it('stamps last used on the token', function (): void {
    $token = tokenFor($this->owner, [ApiScope::ProfileRead]);

    $this->getJson('/api/v1/profile', bearer($token))->assertOk();

    expect($this->owner->tokens()->sole()->last_used_at)->not->toBeNull();
});

it('treats a pre-Phase-10 wildcard token as carrying nothing', function (): void {
    // Issued when no API existed, so it consented to nothing. Reading `*`
    // as full access would hand every old token the whole surface on the
    // day this shipped.
    $token = $this->owner->createToken('Old', ['*'])->plainTextToken;

    $this->getJson('/api/v1/profile', bearer($token))->assertStatus(403);
});
