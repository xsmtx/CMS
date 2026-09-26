<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Domain\Network\NetworkChangeState;
use App\Domain\Operations\OperationType;
use App\Domain\Organizations\OrganizationType;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Network\Jobs\ApplyNetworkChangeJob;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Infrastructure\Operations\Models\Operation;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * The change queue and one change, rendered and then **driven**.
 *
 * Phase 17's rule and the one after it: a screen with no test that renders it
 * has not been tested, and a screen whose actions no test performs has not
 * been tested either. Thirty-five admin write endpoints had never been called
 * by anything when that was last checked, and driving them found three bugs
 * rendering never would.
 *
 * The apply is the endpoint that matters here. It is the only one in this
 * product that can reload a firewall, and three separate things have to be
 * true before it does anything: the permission, a recent password, and a
 * change that has actually been approved.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->other = StaffUser::factory()->create();
    $this->other->assignRole(SystemRole::Administrator);
    $this->other = $this->other->fresh();

    $this->device = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'network_device',
        nodeKey: 'fw1.dc2',
        label: 'fw1',
        source: 'topology:fortigate',
    );
});

function aChange(array $attributes = []): NetworkChange
{
    // `$attributes` on the **left**: `+` keeps the left operand's key, so
    // defaults written first would make every override silently ignored —
    // a helper that looks parameterised and is not.
    return NetworkChange::factory()->create($attributes + [
        'organization_id' => test()->provider->id,
        'resource_node_id' => test()->device->id,
        'requested_by' => test()->other->id,
        'state' => NetworkChangeState::AwaitingApproval,
        'diff' => " config\n-old\n+new",
        'fingerprint_before' => str_repeat('a', 64),
    ]);
}

it('renders the queue', function (): void {
    aChange();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/network/changes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Network/Changes')
            ->has('changes.data', 1)
            // A device to ask about, read from the graph rather than from a
            // devices table.
            ->has('devices', 1)
            ->where('can.approve', true));
});

it('renders one change with its diff', function (): void {
    $change = aChange();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/network/changes/'.$change->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Network/Change')
            ->where('change.diff', " config\n-old\n+new")
            // A status crossing to the browser is two fields.
            ->where('change.status', 'awaiting_approval')
            ->where('change.statusLabel', 'Waiting for approval')
            ->where('can.decide', true));
});

/**
 * The rule a permission cannot express: a permission says who may approve and
 * cannot say whose change. The screen must not offer the button either, or it
 * would be offering something the server then refuses.
 */
it('offers no decision to the person who asked', function (): void {
    $change = aChange(['requested_by' => $this->admin->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/network/changes/'.$change->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.decide', false));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/network/changes/'.$change->id.'/decide', ['decision' => 'approve'])
        ->assertRedirect()
        ->assertSessionHasErrors('decision');

    expect($change->fresh()?->state)->toBe(NetworkChangeState::AwaitingApproval);
});

it('approves and rejects from the screen', function (): void {
    $approved = aChange();
    $rejected = aChange();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/network/changes/'.$approved->id.'/decide', [
            'decision' => 'approve',
            'note' => 'Read it twice.',
        ])
        // `assertSessionHasNoErrors` alone proves nothing — it passes against a
        // 403 and against a 404 — so the redirect is asserted too.
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/network/changes/'.$rejected->id.'/decide', ['decision' => 'reject'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($approved->fresh()?->state)->toBe(NetworkChangeState::Authorized)
        ->and($approved->fresh()?->decision_note)->toBe('Read it twice.')
        ->and($rejected->fresh()?->state)->toBe(NetworkChangeState::Rejected);
});

/**
 * The operation row opens **before** the job is handed to the queue, because
 * an apply that never reached a worker is a firewall change an operator
 * believes went out (ADR 0032).
 */
it('opens an operation before the job reaches a worker', function (): void {
    Queue::fake();

    $change = aChange(['state' => NetworkChangeState::Authorized]);

    $this->actingAs($this->admin, 'staff')
        // The password challenge, already satisfied — `auth.recent` reads a
        // fact about *this* session, so a test that did not put it there
        // would be testing the challenge rather than the apply.
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post('/admin/network/changes/'.$change->id.'/apply', ['note' => 'Agreed on the call.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Queue::assertPushed(ApplyNetworkChangeJob::class);

    $operation = Operation::query()->sole();

    expect($operation->type)->toBe(OperationType::NetworkChangeApply)
        ->and($change->fresh()?->operation_id)->toBe($operation->id);
});

it('will not apply a change that is still waiting on somebody', function (): void {
    Queue::fake();

    $change = aChange();

    $this->actingAs($this->admin, 'staff')
        ->withSession([RequireRecentAuthentication::SESSION_KEY => now()->timestamp])
        ->post('/admin/network/changes/'.$change->id.'/apply')
        ->assertRedirect()
        ->assertSessionHasErrors('decision');

    Queue::assertNothingPushed();
});

/**
 * `owner`-style ordering, from Phase 17: the permission sits **above**
 * `auth.recent` on the route, so somebody who may not apply is refused rather
 * than asked to confirm a password and then refused. Rude, and a small oracle.
 */
it('refuses somebody without the permission before asking for a password', function (): void {
    Queue::fake();

    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);
    $agent = $agent->fresh();

    $change = aChange(['state' => NetworkChangeState::Authorized]);

    $this->actingAs($agent, 'staff')
        ->post('/admin/network/changes/'.$change->id.'/apply')
        // 403, not a redirect to the password challenge.
        ->assertForbidden();

    Queue::assertNothingPushed();
});

/**
 * Reading the queue is one permission and changing anything is three others.
 * Support holds the first and none of the rest.
 */
it('lets support read the queue and offers them no buttons', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);
    $agent = $agent->fresh();

    aChange();

    $this->actingAs($agent, 'staff')
        ->get('/admin/network/changes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.request', false)
            ->where('can.approve', false)
            ->where('can.apply', false));
});

it('refuses the queue to somebody with no network permission at all', function (): void {
    // A role that holds nothing, which is the only way to have a staff
    // account that cannot read this screen: every system role above Support
    // holds more, not less.
    $nobody = StaffUser::factory()->create();
    $nobody->assignRole(Role::query()->create([
        'name' => 'Nothing',
        'slug' => 'nothing',
        'scope' => RoleScope::Staff,
    ]));

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/network/changes')
        ->assertForbidden();
});
