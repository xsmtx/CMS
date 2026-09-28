<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Dcim\RemoteHands;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Dcim\Exceptions\RemoteHandsRefused;
use App\Domain\Dcim\RemoteHandsState;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Dcim\Models\Datacenter;
use App\Infrastructure\Dcim\Models\Rack;
use App\Infrastructure\Dcim\Models\RemoteHandsTask;
use App\Infrastructure\Dcim\Models\Room;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Remote hands (§11).
 *
 * **A record before it is a request.** An audit row saying a machine was
 * opened is worth more than a ticket saying somebody was asked to open it —
 * so every move writes one, and closing a task asks for the sentence the
 * whole record exists for.
 *
 * Asking is Support's: the person on the telephone is exactly who needs a
 * disk swapped. Closing one with the serials is its own permission, because
 * those two fields are the register for the next warranty claim.
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

    $this->hands = app(RemoteHands::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function remoteHandsRack(Organization $provider): Rack
{
    $datacenter = Datacenter::factory()->create(['organization_id' => $provider->id]);

    return Rack::factory()->create([
        'organization_id' => $provider->id,
        'room_id' => Room::factory()->create([
            'organization_id' => $provider->id,
            'datacenter_id' => $datacenter->id,
        ])->id,
    ]);
}

function aTask(Organization $provider, RemoteHandsState $state = RemoteHandsState::Requested): RemoteHandsTask
{
    return RemoteHandsTask::factory()->in($state)->create([
        'organization_id' => $provider->id,
    ]);
}

it('raises a task and writes the audit row', function (): void {
    $rack = remoteHandsRack($this->provider);

    $task = $this->hands->request($this->provider->id, [
        'summary' => 'Replace the failed disk in bay 4',
        'instructions' => 'Amber light on bay 4. Swap it and read both serials back.',
        'rack_id' => $rack->id,
    ], $this->admin);

    expect($task->state)->toBe(RemoteHandsState::Requested)
        ->and($task->rack_id)->toBe($rack->id)
        ->and($task->requested_by)->toBe($this->admin->id)
        ->and($task->requested_at)->not->toBeNull();

    expect(AuditLog::query()->where('action', 'dcim.remote_hands.requested')->exists())->toBeTrue();
});

it('refuses a move that is not on the list', function (): void {
    $task = aTask($this->provider);

    // Waiting to done: nobody has been, so there is nothing to record.
    expect(fn () => $this->hands->transition($task, RemoteHandsState::Done, $this->admin, [
        'outcome' => 'Swapped it.',
    ]))->toThrow(RemoteHandsRefused::class);
});

it('lets a window that fell through go back to waiting', function (): void {
    $task = aTask($this->provider, RemoteHandsState::Scheduled);

    // An ordinary Tuesday. Cancelling and re-raising would lose the thread.
    $moved = $this->hands->transition($task, RemoteHandsState::Requested, $this->admin);

    expect($moved->state)->toBe(RemoteHandsState::Requested);
});

it('refuses to close a task with nothing written on it', function (): void {
    $task = aTask($this->provider, RemoteHandsState::InProgress);

    expect(fn () => $this->hands->transition($task, RemoteHandsState::Done, $this->admin))
        ->toThrow(RemoteHandsRefused::class);

    expect($task->fresh()?->state)->toBe(RemoteHandsState::InProgress);
});

it('records the serials and stamps the clock once', function (): void {
    $task = aTask($this->provider);

    CarbonImmutable::setTestNow('2026-10-29 09:00:00');
    $this->hands->transition($task, RemoteHandsState::InProgress, $this->admin, [
        'technician' => 'Mert from the NOC',
    ]);

    $started = $task->fresh()?->started_at;

    CarbonImmutable::setTestNow('2026-10-29 09:40:00');
    $done = $this->hands->transition($task->fresh(), RemoteHandsState::Done, $this->admin, [
        'old_serial' => 'S1-DEAD',
        'new_serial' => 'S2-SPARE',
        'outcome' => 'Swapped. The array is rebuilding.',
    ]);

    expect($done->state)->toBe(RemoteHandsState::Done)
        ->and($done->started_at?->toDateTimeString())->toBe($started?->toDateTimeString())
        ->and($done->completed_at?->toDateTimeString())->toBe('2026-10-29 09:40:00')
        ->and($done->swappedSerials())->toBeTrue();

    // The outcome is the reason the record exists, so it is on the audit row
    // beside the technician's own name.
    $audit = AuditLog::query()->where('action', 'dcim.remote_hands.done')->sole();

    expect($audit->reason)->toBe('Swapped. The array is rebuilding.')
        ->and($audit->metadata['old_serial'] ?? null)->toBe('S1-DEAD')
        ->and($audit->metadata['technician'] ?? null)->toBe('Mert from the NOC');
});

it('shows what is open, oldest first', function (): void {
    aTask($this->provider)->forceFill(['requested_at' => CarbonImmutable::parse('2026-10-20 10:00')])->save();
    $old = aTask($this->provider);
    $old->forceFill(['summary' => 'The oldest one', 'requested_at' => CarbonImmutable::parse('2026-10-01 10:00')])->save();
    aTask($this->provider, RemoteHandsState::Done)->forceFill(['summary' => 'Long finished'])->save();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/remote-hands')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Dcim/RemoteHands')
            ->where('tasks.data.0.summary', 'The oldest one')
            ->where('tasks.total', 2));

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/remote-hands?all=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tasks.total', 3));
});

it('offers only the moves a task can actually make', function (): void {
    aTask($this->provider, RemoteHandsState::InProgress);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/remote-hands')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tasks.data.0.next.0.value', 'done')
            ->where('tasks.data.0.next.1.value', 'cancelled')
            ->count('tasks.data.0.next', 2));
});

it('raises one from the screen', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/infrastructure/remote-hands', [
            'summary' => 'Reseat the optic in port 12',
            'instructions' => 'Port 12 on the top-of-rack switch is flapping. Pull the optic and put it back.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RemoteHandsTask::query()->where('summary', 'Reseat the optic in port 12')->exists())->toBeTrue();
});

it('refuses a close with no outcome on the form rather than with a server error', function (): void {
    $task = aTask($this->provider, RemoteHandsState::InProgress);

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/infrastructure/remote-hands/{$task->id}", ['state' => 'done'])
        ->assertRedirect()
        ->assertSessionHasErrors('state');

    expect($task->fresh()?->state)->toBe(RemoteHandsState::InProgress);
});

it('lets support ask and not close', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);
    $agent = $agent->fresh();

    $this->actingAs($agent, 'staff')
        ->post('/admin/infrastructure/remote-hands', [
            'summary' => 'Power cycle web-3',
            'instructions' => 'It stopped answering. Press the button on the front.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $task = RemoteHandsTask::query()->sole();

    $this->actingAs($agent, 'staff')
        ->post("/admin/infrastructure/remote-hands/{$task->id}", ['state' => 'in_progress'])
        ->assertRedirect();

    // Closing carries the serials, which is a different permission.
    $this->actingAs($agent, 'staff')
        ->post("/admin/infrastructure/remote-hands/{$task->fresh()->id}", [
            'state' => 'done',
            'outcome' => 'Pressed it.',
        ])
        ->assertForbidden();
});

it('refuses somebody with no datacenter permission at all', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/infrastructure/remote-hands')
        ->assertForbidden();
});

it('does not show another organization its neighbour’s tasks', function (): void {
    $other = Organization::factory()->create([
        'parent_id' => $this->provider->id,
        'type' => OrganizationType::Customer,
    ]);

    aTask($this->provider);
    RemoteHandsTask::factory()->create(['organization_id' => $other->id, 'summary' => 'Not ours']);

    app(OrganizationContext::class)->set($other->id);

    expect(RemoteHandsTask::query()->pluck('summary')->all())->toBe(['Not ours']);
});
