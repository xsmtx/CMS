<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Dcim\PlaceDevice;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Dcim\Exceptions\RackRefused;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Dcim\Models\Datacenter;
use App\Infrastructure\Dcim\Models\Rack;
use App\Infrastructure\Dcim\Models\RackPosition;
use App\Infrastructure\Dcim\Models\Room;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The DCIM spine (§11).
 *
 * **The first thing in this product no adapter can discover.** A rack is not
 * an API; somebody types it in, and every rule here is about not letting them
 * type something that makes the diagram wrong — because a rack diagram that
 * does not match the building is worse than none, since somebody will send a
 * technician to the wrong cabinet.
 *
 * The overlap rule is the one the database cannot express: a unique key on
 * `(rack_id, start_unit)` stops two things starting on one unit and says
 * nothing about a 2U device landing on the 1U above it.
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

    $this->devices = app(PlaceDevice::class);
});

function aRack(Organization $provider, int $units = 42): Rack
{
    $datacenter = Datacenter::factory()->create(['organization_id' => $provider->id]);
    $room = Room::factory()->create([
        'organization_id' => $provider->id,
        'datacenter_id' => $datacenter->id,
    ]);

    return Rack::factory()->of($units)->create([
        'organization_id' => $provider->id,
        'room_id' => $room->id,
    ]);
}

it('puts a device in a rack and counts what is left', function (): void {
    $rack = aRack($this->provider);

    $this->devices->place($rack, 10, 2, null, 'Switch', $this->admin);

    $position = RackPosition::query()->sole();

    expect($position->start_unit)->toBe(10)
        // A 2U device at 10 occupies 10 and 11. Units are numbered from the
        // bottom, which is how they are labelled on the rails.
        ->and($position->units())->toBe([10, 11])
        ->and($position->endUnit())->toBe(11)
        ->and($rack->fresh()->load('positions')->freeUnits())->toBe(40);
});

/**
 * The rule no index can express: a unique key on `(rack_id, start_unit)`
 * would have let this through.
 */
it('refuses a device that lands on top of one already there', function (): void {
    $rack = aRack($this->provider);

    $this->devices->place($rack, 10, 4, null, 'Chassis', $this->admin);

    expect(fn () => $this->devices->place($rack, 12, 1, null, 'Switch', $this->admin))
        ->toThrow(RackRefused::class);

    expect(RackPosition::query()->count())->toBe(1);
});

/** And the other way round: a tall device reaching up into one above it. */
it('refuses a device that reaches up into one already there', function (): void {
    $rack = aRack($this->provider);

    $this->devices->place($rack, 20, 1, null, 'Switch', $this->admin);

    expect(fn () => $this->devices->place($rack, 18, 4, null, 'Chassis', $this->admin))
        ->toThrow(RackRefused::class);
});

/**
 * "Unit 41 plus three units is past the top of a 42U rack" is a sentence
 * somebody can act on. Clamping it silently would put a machine where it is
 * not.
 */
it('refuses a device that would end above the top of the rack', function (): void {
    $rack = aRack($this->provider, units: 42);

    expect(fn () => $this->devices->place($rack, 41, 3, null, 'Chassis', $this->admin))
        ->toThrow(RackRefused::class, '42U');
});

/** A blank row in an elevation is a unit an operator will think is free. */
it('refuses a position with neither a server nor a name', function (): void {
    $rack = aRack($this->provider);

    expect(fn () => $this->devices->place($rack, 1, 1, null, '  ', $this->admin))
        ->toThrow(RackRefused::class);
});

/**
 * One machine is in one rack. Moving it is a delete and an insert, done in
 * one call so a half-moved server cannot exist.
 */
it('moves a server rather than putting it in two racks', function (): void {
    $first = aRack($this->provider);
    $second = aRack($this->provider);

    $server = Server::factory()->create(['organization_id' => $this->provider->id]);

    $this->devices->place($first, 5, 1, $server, null, $this->admin);
    $this->devices->place($second, 7, 1, $server, null, $this->admin);

    $position = RackPosition::query()->sole();

    expect($position->rack_id)->toBe($second->id)
        ->and($position->start_unit)->toBe(7)
        // The server's own name wins over a label: it is what an operator
        // searched for to get here.
        ->and($position->displayName())->toBe($server->name);
});

it('takes a device out and frees its units', function (): void {
    $rack = aRack($this->provider);

    $position = $this->devices->place($rack, 10, 2, null, 'Switch', $this->admin);

    expect($rack->fresh()->load('positions')->freeUnits())->toBe(40);

    $this->devices->remove($position, $this->admin);

    expect($rack->fresh()->load('positions')->freeUnits())->toBe(42)
        ->and(RackPosition::query()->count())->toBe(0);
});

it('drives the list, with how much of each rack is free', function (): void {
    $rack = aRack($this->provider);
    $this->devices->place($rack, 1, 2, null, 'Patch panel', $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/infrastructure/dcim')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Dcim/Index')
            ->has('datacenters', 1)
            ->has('datacenters.0.rooms.0.racks', 1)
            ->where('datacenters.0.rooms.0.racks.0.free', 40)
            ->where('datacenters.0.rooms.0.racks.0.used', 2)
            ->where('can.manage', true));
});

/**
 * A device occupying four units is drawn once and continued three times:
 * repeating its name would read as four machines, which is the mistake a rack
 * diagram exists to stop.
 */
it('drives the elevation, top to bottom, continuing a tall device', function (): void {
    $rack = aRack($this->provider, units: 5);
    $this->devices->place($rack, 2, 3, null, 'Chassis', $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->get("/admin/infrastructure/dcim/racks/{$rack->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Dcim/Rack')
            ->has('units', 5)
            // Top first.
            ->where('units.0.unit', 5)
            ->where('units.0.device', null)
            // Units 4 and 3 are the chassis continuing; unit 2 is where it
            // is drawn, because that is its lowest unit.
            ->where('units.1.covered', true)
            ->where('units.1.device', null)
            ->where('units.3.unit', 2)
            ->where('units.3.device.name', 'Chassis')
            ->where('units.3.device.height', 3)
            ->where('rack.free', 2));
});

it('refuses the screen to somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/infrastructure/dcim')
        ->assertForbidden();
});

it('refuses a placement to somebody who may only look', function (): void {
    $rack = aRack($this->provider);
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/infrastructure/dcim')
        ->assertOk();

    $this->actingAs($agent->fresh(), 'staff')
        ->post("/admin/infrastructure/dcim/racks/{$rack->id}/positions", [
            'start_unit' => 1,
            'unit_height' => 1,
            'label' => 'Switch',
        ])
        ->assertForbidden();

    expect(RackPosition::query()->count())->toBe(0);
});

/** Reached by id from another rack. 404, never 403. */
it('answers 404 for a position in a different rack', function (): void {
    $first = aRack($this->provider);
    $second = aRack($this->provider);

    $position = $this->devices->place($first, 1, 1, null, 'Switch', $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->delete("/admin/infrastructure/dcim/racks/{$second->id}/positions/{$position->id}")
        ->assertNotFound();

    expect(RackPosition::query()->count())->toBe(1);
});

it('adds a rack from the screen', function (): void {
    $rack = aRack($this->provider);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/infrastructure/dcim/racks', [
            'room_id' => $rack->room_id,
            'name' => 'R99',
            'units' => 47,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Rack::query()->where('name', 'R99')->sole()->units)->toBe(47);
});

/** A refusal reaches the form as a sentence, never as a 500 page. */
it('shows a refusal on the form rather than a server error', function (): void {
    $rack = aRack($this->provider);
    $this->devices->place($rack, 10, 2, null, 'Chassis', $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/infrastructure/dcim/racks/{$rack->id}/positions", [
            'start_unit' => 11,
            'unit_height' => 1,
            'label' => 'Switch',
        ])
        ->assertSessionHasErrors('start_unit');

    expect(RackPosition::query()->count())->toBe(1);
});
