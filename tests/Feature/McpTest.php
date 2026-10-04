<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Api\Devices\DeviceSessions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Api\DevicePlatform;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\IncidentState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Testing\TestResponse;

/**
 * The MCP endpoint, spoken to (ADR 0051).
 *
 * `McpSurfaceTest` keeps the decision; this one proves the protocol works and
 * that the decision is enforced at the door rather than only declared in an
 * enum. The two most important things here are that a token reaches only what
 * its scopes allow — including in the **catalogue**, so a model never spends a
 * turn discovering a refusal — and that a tool refusing comes back as content
 * a model can read rather than as a transport error.
 */
beforeEach(function (): void {
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

    $this->sessions = app(DeviceSessions::class);
});

/**
 * @param  list<string>  $scopes
 */
function mcpToken(array $scopes): string
{
    return test()->sessions->open(
        owner: test()->staff,
        name: 'Assistant',
        platform: DevicePlatform::Other,
        scopes: $scopes,
        staff: true,
    )->accessToken;
}

/**
 * @param  array<string, mixed>  $params
 */
function rpc(string $token, string $method, array $params = [], mixed $id = 1): TestResponse
{
    return test()->withToken($token)->postJson('/api/mcp', array_filter([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params === [] ? null : $params,
    ], static fn (mixed $value): bool => $value !== null));
}

it('refuses anybody without a staff token', function (): void {
    test()->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
        ->assertUnauthorized();
});

it('answers initialize with the revision it actually speaks', function (): void {
    rpc(mcpToken(['alerts:read']), 'initialize')
        ->assertOk()
        // Stated rather than echoed: answering with whatever a client asked
        // for would be claiming to support a revision nobody here has read.
        ->assertJsonPath('result.protocolVersion', '2024-11-05')
        ->assertJsonPath('result.serverInfo.name', 'infracms');
});

it('takes a notification without answering it', function (): void {
    // No id means no reply. Replying to `initialized` is a protocol error.
    test()->withToken(mcpToken(['alerts:read']))
        ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
        ->assertStatus(202);
});

/**
 * A catalogue that advertised a tool the caller cannot run is a model
 * spending a turn discovering that.
 */
it('lists only the tools this token may actually run', function (): void {
    $response = rpc(mcpToken(['incidents:read']), 'tools/list')->assertOk();

    $names = array_column($response->json('result.tools'), 'name');

    expect($names)->toContain('incidents_list', 'incident_get')
        ->and($names)->not->toContain('tickets_list')
        ->and($names)->not->toContain('alerts_list');
});

it('gives every listed tool a description and a schema', function (): void {
    $tools = rpc(mcpToken(['incidents:read']), 'tools/list')->assertOk()->json('result.tools');

    foreach ($tools as $tool) {
        // The description is the whole interface: it is the only thing a
        // model reads when deciding whether this answers the question.
        expect($tool['description'])->not->toBe('')
            ->and($tool['inputSchema']['type'])->toBe('object');
    }
});

it('runs a tool and answers with the same rows the API would', function (): void {
    $incident = Incident::factory()->create([
        'organization_id' => $this->provider->id,
        'state' => IncidentState::Investigating,
        'severity' => AlertSeverity::Critical,
    ]);

    $response = rpc(mcpToken(['incidents:read']), 'tools/call', [
        'name' => 'incidents_list',
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.content.0.text'))->toContain($incident->reference);
});

/**
 * The refusal shape the protocol asks for, and the reason it matters.
 */
it('answers a scope it does not carry as content, not as a broken server', function (): void {
    $response = rpc(mcpToken(['incidents:read']), 'tools/call', [
        'name' => 'tickets_list',
    ])->assertOk();

    // A client that got a 403 would report "the server is broken" where the
    // truth is "this token may not read tickets", and the operator would go
    // looking in the wrong place.
    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('error'))->toBeNull();
});

it('reaches nothing when the holder lacks the permission behind the scope', function (): void {
    Incident::factory()->create([
        'organization_id' => $this->provider->id,
        'state' => IncidentState::Investigating,
        'severity' => AlertSeverity::Critical,
    ]);

    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $token = $this->sessions->open(
        owner: $nobody->fresh(),
        name: 'Assistant',
        platform: DevicePlatform::Other,
        scopes: ['incidents:read'],
        staff: true,
    )->accessToken;

    // A scope only narrows and never grants, on the surface where the thing
    // asking is a model.
    $response = rpc($token, 'tools/call', ['name' => 'incidents_list'])->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
});

it('says so plainly when an id is not here', function (): void {
    $response = rpc(mcpToken(['incidents:read']), 'tools/call', [
        'name' => 'incident_get',
        'arguments' => ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.content.0.text'))->toBe('Nothing here has that id.');
});

it('refuses a tool that does not exist', function (): void {
    rpc(mcpToken(['incidents:read']), 'tools/call', ['name' => 'terminate_service'])
        ->assertOk()
        // -32602 is the protocol's own code for a bad parameter.
        ->assertJsonPath('error.code', -32602);
});

it('refuses a method it does not speak', function (): void {
    rpc(mcpToken(['incidents:read']), 'resources/list')
        ->assertOk()
        ->assertJsonPath('error.code', -32601);
});

/**
 * The lookup is exact, and here that is a safety property rather than a
 * convenience one.
 */
it('answers a near miss with nothing rather than the wrong machine', function (): void {
    ResourceNode::factory()->keyed('web-7')->create([
        'organization_id' => $this->provider->id,
        'kind' => ResourceKind::Server,
    ]);

    $response = rpc(mcpToken(['dcim:read']), 'tools/call', [
        'name' => 'resource_get',
        'arguments' => ['key' => 'web-'],
    ])->assertOk();

    expect($response->json('result.content.0.text'))->toBe('Nothing here has that id.');
});
