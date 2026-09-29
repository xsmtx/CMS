<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Api\Devices\DeviceSessions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Api\DevicePlatform;
use App\Domain\Identity\AccountStatus;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Network\GrantableCapability;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\IncidentState;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Infrastructure\Support\Models\Ticket;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The staff surface (ADR 0049).
 *
 * The endpoints are thin, so what is worth testing is the four constraints
 * the ADR put on them: a staff token is always scoped, always expires, never
 * outlives the account, and reaches only what its holder's permissions allow.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->staff = StaffUser::factory()->create([
        'organization_id' => $this->provider->id,
        'email' => 'operator@infracms.test',
        'password' => 'correct-horse-battery',
    ]);

    $this->staff->assignRole(SystemRole::Administrator);
    $this->staff = $this->staff->fresh();

    $this->sessions = app(DeviceSessions::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<string>  $scopes
 */
function staffToken(array $scopes): string
{
    return test()->sessions->open(
        owner: test()->staff,
        name: 'Pixel',
        platform: DevicePlatform::Android,
        scopes: $scopes,
        staff: true,
    )->accessToken;
}

function anIncident(): Incident
{
    return Incident::factory()->create([
        'organization_id' => test()->provider->id,
        'state' => IncidentState::Investigating,
        'severity' => AlertSeverity::Critical,
    ]);
}

it('opens a staff session through the staff route', function (): void {
    test()->postJson('/api/v1/staff/auth/token', [
        'email' => 'operator@infracms.test',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pixel',
        'platform' => 'android',
        'scopes' => ['alerts:read', 'incidents:read'],
    ])->assertCreated();

    $token = PersonalAccessToken::query()->firstOrFail();

    expect($token->abilities)->toBe(['alerts:read', 'incidents:read'])
        // Always expires: there is no unlimited option on this guard.
        ->and($token->expires_at)->not->toBeNull();
});

it('refuses a client scope asked for on the staff route', function (): void {
    test()->postJson('/api/v1/staff/auth/token', [
        'email' => 'operator@infracms.test',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pixel',
        // A real scope, on the wrong guard. It means something else there.
        'scopes' => ['services:write'],
    ])->assertStatus(422);
});

it('lets a scoped token read the incidents', function (): void {
    $incident = anIncident();

    test()->withToken(staffToken(['incidents:read']))
        ->getJson('/api/v1/staff/incidents')
        ->assertOk()
        ->assertJsonPath('data.0.reference', $incident->reference)
        // Two fields, as everywhere: the value for the tone and the word.
        ->assertJsonPath('data.0.state', 'investigating')
        ->assertJsonPath('data.0.stateTone', $incident->state->tone());
});

it('refuses a token that does not carry the scope', function (): void {
    anIncident();

    test()->withToken(staffToken(['alerts:read']))
        ->getJson('/api/v1/staff/incidents')
        ->assertForbidden();
});

/**
 * A scope only narrows, and never grants. This is the check that keeps it
 * that way on the guard where the ceiling is every permission there is.
 */
it('reaches nothing when the holder lacks the permission behind the scope', function (): void {
    anIncident();

    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $token = $this->sessions->open(
        owner: $nobody->fresh(),
        name: 'Pixel',
        platform: DevicePlatform::Android,
        scopes: ['incidents:read'],
        staff: true,
    )->accessToken;

    test()->withToken($token)
        ->getJson('/api/v1/staff/incidents')
        ->assertForbidden();
});

it('refuses a staff token carrying no scope at all', function (): void {
    $token = $this->sessions->open(
        owner: $this->staff,
        name: 'Pixel',
        platform: DevicePlatform::Android,
        scopes: [],
        staff: true,
    )->accessToken;

    // There is no `*` and never was: a staff token that narrowed nothing
    // would be every staff permission in a bearer string.
    test()->withToken($token)
        ->getJson('/api/v1/staff/incidents')
        ->assertUnauthorized();
});

it('refuses a token whose expiry has passed', function (): void {
    $token = staffToken(['incidents:read']);

    PersonalAccessToken::query()->update(['expires_at' => CarbonImmutable::now()->subMinute()]);

    test()->withToken($token)
        ->getJson('/api/v1/staff/incidents')
        ->assertUnauthorized();
});

it('refuses a token whose account is no longer active', function (): void {
    $token = staffToken(['incidents:read']);

    $this->staff->forceFill(['status' => AccountStatus::Suspended])->save();

    // Revoking the account revokes the tokens, which is the property that
    // makes issuing one safe at all.
    test()->withToken($token)
        ->getJson('/api/v1/staff/incidents')
        ->assertUnauthorized();
});

it('will not read the staff surface with a client token', function (): void {
    // The two guards are separate all the way down. A contact's token is not
    // a small staff token.
    test()->withToken(staffToken(['incidents:read']))
        ->getJson('/api/v1/profile')
        ->assertUnauthorized();
});

it('posts an update through the same use case the admin screen calls', function (): void {
    $incident = anIncident();

    test()->withToken(staffToken(['incidents:write']))
        ->postJson('/api/v1/staff/incidents/'.$incident->id.'/updates', [
            'body' => 'Failover completed, watching the error rate.',
            'state' => 'monitoring',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'monitoring');

    // The factory builds the row directly, so this update is the first
    // one on it — an incident opened through `Incidents::open()` would
    // already have one.
    expect($incident->refresh()->updates()->count())->toBe(1);
});

it('will not resolve an incident through the update route', function (): void {
    $incident = anIncident();

    // Resolving has a figure to freeze and a timestamp to write, so it has
    // its own route; a second way to reach it would be the one that forgot.
    test()->withToken(staffToken(['incidents:write']))
        ->postJson('/api/v1/staff/incidents/'.$incident->id.'/updates', [
            'body' => 'All clear.',
            'state' => 'resolved',
        ])
        ->assertStatus(422);
});

it('resolves through the route that freezes the impact', function (): void {
    $incident = anIncident();

    test()->withToken(staffToken(['incidents:write']))
        ->postJson('/api/v1/staff/incidents/'.$incident->id.'/resolve', [
            'body' => 'Root cause was a failed disk; replaced.',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'resolved');

    expect($incident->refresh()->resolved_at)->not->toBeNull();
});

it('answers a refusal as a 422 with the sentence, not a 500', function (): void {
    $incident = anIncident();

    test()->withToken(staffToken(['incidents:write']))
        ->postJson('/api/v1/staff/incidents/'.$incident->id.'/resolve', ['body' => 'Done.'])
        ->assertOk();

    // Resolved once. The second attempt is a caller's mistake.
    test()->withToken(staffToken(['incidents:write']))
        ->postJson('/api/v1/staff/incidents/'.$incident->id.'/resolve', ['body' => 'Done again.'])
        ->assertStatus(422);
});

it('will not accept an update with nothing in it', function (): void {
    $incident = anIncident();

    // An update that moves a state and tells nobody anything is the move
    // that makes a status page useless.
    test()->withToken(staffToken(['incidents:write']))
        ->postJson('/api/v1/staff/incidents/'.$incident->id.'/updates', ['state' => 'identified'])
        ->assertStatus(422);
});

it('signs a staff device out through the staff route', function (): void {
    $token = staffToken(['incidents:read']);

    test()->withToken($token)
        ->deleteJson('/api/v1/staff/auth/token')
        ->assertOk();

    test()->withToken($token)
        ->getJson('/api/v1/staff/incidents')
        ->assertUnauthorized();
});

it('lists the tickets whose turn it is, first', function (): void {
    $waiting = Ticket::factory()->create([
        'organization_id' => $this->provider->id,
        'subject' => 'Site is down',
        'status' => TicketStatus::CustomerReply,
    ]);

    Ticket::factory()->create([
        'organization_id' => $this->provider->id,
        'subject' => 'Already answered',
        'status' => TicketStatus::Answered,
    ]);

    test()->withToken(staffToken(['tickets:read']))
        ->getJson('/api/v1/staff/tickets')
        ->assertOk()
        // Whose turn it is is the one question an agent opens this for.
        ->assertJsonPath('data.0.subject', $waiting->subject)
        ->assertJsonPath('data.0.awaitingUs', true);
});

it('replies to a ticket through the one place that moves its clock', function (): void {
    $ticket = Ticket::factory()->create([
        'organization_id' => $this->provider->id,
        'status' => TicketStatus::CustomerReply,
    ]);

    test()->withToken(staffToken(['tickets:write']))
        ->postJson('/api/v1/staff/tickets/'.$ticket->id.'/replies', [
            'body' => 'We have restarted the pool; please try again.',
        ])
        ->assertCreated()
        // ReplyToTicket decided the status, not this controller (ADR 0030).
        ->assertJsonPath('data.status', 'answered');
});

it('will not reply with a token that can only read', function (): void {
    $ticket = Ticket::factory()->create([
        'organization_id' => $this->provider->id,
    ]);

    test()->withToken(staffToken(['tickets:read']))
        ->postJson('/api/v1/staff/tickets/'.$ticket->id.'/replies', ['body' => 'Hello there.'])
        ->assertForbidden();
});

it('offers no route that applies a device change', function (): void {
    // The guard in StaffApiSurfaceTest says this in general; this says it
    // about the one endpoint somebody would most plausibly add.
    test()->withToken(staffToken(['changes:write']))
        ->postJson('/api/v1/staff/device-changes/nonexistent/apply')
        ->assertNotFound();
});

it('grants access to somebody else and refuses granting it to yourself', function (): void {
    $colleague = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    test()->withToken(staffToken(['access:write']))
        ->postJson('/api/v1/staff/access-grants', [
            'holder_id' => $colleague->id,
            'capability' => GrantableCapability::cases()[0]->value,
            'reason' => 'Console access to finish the migration.',
            'minutes' => 60,
        ])
        ->assertCreated();

    // A permission says who may grant and cannot say to whom.
    test()->withToken(staffToken(['access:write']))
        ->postJson('/api/v1/staff/access-grants', [
            'holder_id' => $this->staff->id,
            'capability' => GrantableCapability::cases()[0]->value,
            'reason' => 'Because I want it.',
            'minutes' => 60,
        ])
        ->assertStatus(422);
});

it('refuses a window shorter than the floor', function (): void {
    $colleague = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    // Under five minutes is a grant somebody is about to give again.
    test()->withToken(staffToken(['access:write']))
        ->postJson('/api/v1/staff/access-grants', [
            'holder_id' => $colleague->id,
            'capability' => GrantableCapability::cases()[0]->value,
            'reason' => 'Just a moment.',
            'minutes' => 2,
        ])
        ->assertStatus(422);
});

it('resolves a machine by its node key and says what is underneath', function (): void {
    $node = ResourceNode::factory()->keyed('web-7')->create([
        'organization_id' => $this->provider->id,
        'kind' => ResourceKind::Server,
    ]);

    test()->withToken(staffToken(['dcim:read']))
        ->getJson('/api/v1/staff/lookup?key='.$node->node_key)
        ->assertOk()
        ->assertJsonPath('data.key', 'web-7')
        ->assertJsonPath('data.kind', 'server')
        // The thing only this platform can answer.
        ->assertJsonPath('data.impact.services', 0);
});

it('answers nothing rather than a near miss', function (): void {
    ResourceNode::factory()->keyed('web-7')->create([
        'organization_id' => $this->provider->id,
        'kind' => ResourceKind::Server,
    ]);

    // A technician is holding the label. A near match would be the wrong
    // machine confidently identified, and somebody pulls the wrong disk.
    test()->withToken(staffToken(['dcim:read']))
        ->getJson('/api/v1/staff/lookup?key=web-')
        ->assertOk()
        ->assertJsonPath('data', null);
});
