<?php

declare(strict_types=1);

use App\Application\Provisioning\Exceptions\PlacementFailed;
use App\Application\Provisioning\PlaceService;
use App\Domain\Provisioning\PlacementStrategy;
use App\Domain\Provisioning\ServerStatus;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use Database\Seeders\ProviderOrganizationSeeder;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    $this->group = ServerGroup::factory()->create();
});

function placeOn(Server $server, int $count, ServiceStatus $status = ServiceStatus::Active): void
{
    Service::factory()->count($count)->create([
        'organization_id' => $server->organization_id,
        'server_id' => $server->id,
        'status' => $status->value,
    ]);
}

it('picks the node holding the fewest services', function (): void {
    $busy = Server::factory()->inGroup($this->group)->create(['name' => 'busy']);
    $quiet = Server::factory()->inGroup($this->group)->create(['name' => 'quiet']);

    placeOn($busy, 3);
    placeOn($quiet, 1);

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($quiet->id);
});

it('counts a suspended service against capacity but not a terminated one', function (): void {
    $a = Server::factory()->inGroup($this->group)->create();
    $b = Server::factory()->inGroup($this->group)->create();

    // Suspended still occupies a slot: the account is still there.
    placeOn($a, 2, ServiceStatus::Suspended);
    placeOn($b, 2, ServiceStatus::Terminated);

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($b->id);
});

it('never picks a node in maintenance', function (): void {
    Server::factory()->inGroup($this->group)->status(ServerStatus::Maintenance)->create();
    $available = Server::factory()->inGroup($this->group)->create();

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($available->id);
});

it('never picks a node that is full, and marks it so', function (): void {
    $full = Server::factory()->inGroup($this->group)->capacity(2)->create();
    $free = Server::factory()->inGroup($this->group)->capacity(10)->create();

    placeOn($full, 2);

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($free->id);
});

it('fails loudly when every node is full', function (): void {
    $server = Server::factory()->inGroup($this->group)->capacity(1)->create();
    placeOn($server, 1);

    // A service placed on a node that cannot hold it is a support ticket
    // tomorrow; one that failed to place is an operator's queue today.
    expect(fn (): Server => app(PlaceService::class)->handle($this->group))
        ->toThrow(PlacementFailed::class);

    expect($server->fresh()?->status)->toBe(ServerStatus::Full);
});

it('fails when the group has no nodes at all', function (): void {
    expect(fn (): Server => app(PlaceService::class)->handle($this->group))
        ->toThrow(PlacementFailed::class);
});

it('spreads by weight when asked to', function (): void {
    $this->group->update(['placement_strategy' => PlacementStrategy::Weighted->value]);

    $big = Server::factory()->inGroup($this->group)->create(['weight' => 4]);
    $small = Server::factory()->inGroup($this->group)->create(['weight' => 1]);

    // Four on the big node and one on the small one is the same load per
    // unit of weight; the tiebreak is stable, and adding one more to the
    // small node makes the big one the clear choice.
    placeOn($big, 4);
    placeOn($small, 2);

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($big->id);
});

it('prefers the node with the most headroom when capacity aware', function (): void {
    $this->group->update(['placement_strategy' => PlacementStrategy::CapacityAware->value]);

    $tight = Server::factory()->inGroup($this->group)->capacity(10)->create();
    $roomy = Server::factory()->inGroup($this->group)->capacity(100)->create();

    placeOn($tight, 8);
    placeOn($roomy, 8);

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($roomy->id);
});

it('prefers the customers region, and falls back rather than failing', function (): void {
    $this->group->update(['placement_strategy' => PlacementStrategy::RegionAware->value]);

    $here = Server::factory()->inGroup($this->group)->create(['region' => 'TR']);
    $elsewhere = Server::factory()->inGroup($this->group)->create(['region' => 'DE']);

    expect(app(PlaceService::class)->handle($this->group, 'TR')->id)->toBe($here->id);

    // Nothing in the customer's region: better served somewhere else than
    // not at all, and the service records where it actually landed.
    placeOn($here, 1);
    expect(app(PlaceService::class)->handle($this->group, 'FR')->id)
        ->toBe($elsewhere->id);
});

it('refuses to guess when the group places by hand', function (): void {
    $this->group->update(['placement_strategy' => PlacementStrategy::Manual->value]);
    Server::factory()->inGroup($this->group)->create();

    expect(fn (): Server => app(PlaceService::class)->handle($this->group))
        ->toThrow(PlacementFailed::class);
});
