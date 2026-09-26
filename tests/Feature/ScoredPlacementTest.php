<?php

declare(strict_types=1);

use App\Application\Infrastructure\ResourceGraph;
use App\Application\Provisioning\PlaceService;
use App\Application\Provisioning\ScorePlacement;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricUnit;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Provisioning\PlacementComponent;
use App\Domain\Provisioning\PlacementFactor;
use App\Domain\Provisioning\PlacementStrategy;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServicePlacement;
use App\Infrastructure\Resources\Models\ResourceMetric;
use Carbon\CarbonImmutable;
use Database\Factories\ResourceMetricDayFactory;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * Placement over what the monitoring system actually said.
 *
 * The engine is a weighted mean and the interesting behaviour is all in what it
 * refuses to do: it never treats an unmonitored node as an idle one, it never
 * lets a discovered fact remove a node from the running, and it writes down why
 * in numbers rather than in a sentence that would come out in whichever language
 * the queue worker happened to be running in.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->group = ServerGroup::factory()->create([
        'placement_strategy' => PlacementStrategy::Scored->value,
    ]);

    $this->node = fn (Server $server) => app(ResourceGraph::class)->upsertNode(
        $server->organization_id,
        ResourceKind::Server,
        $server->id,
        $server->name,
        subject: $server,
        attributes: ['hostname' => $server->hostname],
    );

    $this->reading = function (Server $server, MetricKind $metric, float $value, MetricUnit $unit = MetricUnit::Ratio): void {
        $node = ($this->node)($server);

        ResourceMetric::query()->create([
            'organization_id' => $server->organization_id,
            'resource_node_id' => $node->id,
            'metric' => $metric->value,
            'unit' => $unit->value,
            'value' => $value,
            'sampled_at' => CarbonImmutable::now(),
            'source' => 'test',
        ]);
    };

    $this->service = fn (): Service => Service::factory()->create();
});

it('sends work to the node with room in it', function (): void {
    $hot = Server::factory()->inGroup($this->group)->create(['name' => 'hot']);
    $cool = Server::factory()->inGroup($this->group)->create(['name' => 'cool']);

    ($this->reading)($hot, MetricKind::CpuUtilisation, 0.94);
    ($this->reading)($hot, MetricKind::DiskUsed, 940.0, MetricUnit::Bytes);
    ($this->reading)($hot, MetricKind::DiskTotal, 1000.0, MetricUnit::Bytes);

    ($this->reading)($cool, MetricKind::CpuUtilisation, 0.11);
    ($this->reading)($cool, MetricKind::DiskUsed, 120.0, MetricUnit::Bytes);
    ($this->reading)($cool, MetricKind::DiskTotal, 1000.0, MetricUnit::Bytes);

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($cool->id);
});

/**
 * The trap this whole design is arranged around. A node nothing reports on
 * would score zero on every measured factor, which is the lowest possible
 * score — so every service would go to the one box the exporter forgot.
 */
it('does not treat a node nobody monitors as an empty one', function (): void {
    $known = Server::factory()->inGroup($this->group)->create(['name' => 'known']);
    $unseen = Server::factory()->inGroup($this->group)->create(['name' => 'unseen']);

    // Comfortable, but not empty.
    ($this->reading)($known, MetricKind::CpuUtilisation, 0.30);
    ($this->reading)($known, MetricKind::DiskUsed, 300.0, MetricUnit::Bytes);
    ($this->reading)($known, MetricKind::DiskTotal, 1000.0, MetricUnit::Bytes);

    Service::factory()->count(4)->create([
        'organization_id' => $unseen->organization_id,
        'server_id' => $unseen->id,
    ]);

    // The unmonitored node holds more accounts and answers nothing else, so
    // the one with readings wins on the factors both of them answered.
    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($known->id);
});

/**
 * The other half of the same rule: what a node did not report is filled in with
 * what its peers averaged, and the row says which numbers were assumed rather
 * than read.
 */
it('assumes a missing reading from what the other candidates averaged', function (): void {
    $measured = Server::factory()->inGroup($this->group)->create(['name' => 'measured']);
    $silent = Server::factory()->inGroup($this->group)->create(['name' => 'silent']);

    ($this->reading)($measured, MetricKind::CpuUtilisation, 0.60);
    ($this->node)($silent);

    $decisions = app(ScorePlacement::class)->forServers(
        Server::query()->whereIn('id', [$measured->id, $silent->id])->get(),
        [],
    );

    $cpu = function (string $serverId) use ($decisions): PlacementComponent {
        foreach ($decisions[$serverId]->components as $component) {
            if ($component->factor === PlacementFactor::Cpu) {
                return $component;
            }
        }

        throw new RuntimeException('no cpu component');
    };

    expect($cpu($measured->id)->assumed)->toBeFalse()
        ->and($cpu($measured->id)->measure)->toBe(0.6)
        // Nothing reported this node's CPU, so it is assumed to be as loaded as
        // the candidate that did report: the same score, no measurement, and
        // the row says which of the two it is.
        ->and($cpu($silent->id)->assumed)->toBeTrue()
        ->and($cpu($silent->id)->score)->toBe(0.4)
        ->and($cpu($silent->id)->measure)->toBeNull();
});

/**
 * Bytes used without bytes total is not a fullness. The forecast declines the
 * same way, for the same reason: a ceiling nobody reported is a ceiling
 * somebody invented.
 */
it('declines to score disk when only half the pair was reported', function (): void {
    $server = Server::factory()->inGroup($this->group)->create();

    ($this->reading)($server, MetricKind::DiskUsed, 900.0, MetricUnit::Bytes);

    app(PlaceService::class)->handle($this->group, for: ($this->service)());

    $factors = ServicePlacement::query()->sole()->factors ?? [];

    expect($factors)->not->toHaveKey(PlacementFactor::Disk->value)
        ->and($factors)->toHaveKey(PlacementFactor::Accounts->value);
});

/**
 * A monitoring adapter that breaks at three in the morning must not be able to
 * fail every provisioning job on the installation.
 */
it('pushes a failing node down without taking it out of the running', function (): void {
    $sick = Server::factory()->inGroup($this->group)->create(['name' => 'sick']);

    ($this->node)($sick)->forceFill(['health' => 'failing'])->save();

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($sick->id);

    $healthy = Server::factory()->inGroup($this->group)->create(['name' => 'healthy']);
    ($this->node)($healthy)->forceFill(['health' => 'ok'])->save();

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($healthy->id);
});

it('keeps a customer off the one node that already holds all of them', function (): void {
    $crowded = Server::factory()->inGroup($this->group)->create(['name' => 'crowded']);
    $spare = Server::factory()->inGroup($this->group)->create(['name' => 'spare']);

    $service = ($this->service)();

    // Three of this customer's services here, and three of somebody else's
    // there: the same account count, a different answer.
    Service::factory()->count(3)->create([
        'organization_id' => $service->organization_id,
        'customer_id' => $service->customer_id,
        'server_id' => $crowded->id,
    ]);

    Service::factory()->count(3)->create([
        'organization_id' => $spare->organization_id,
        'server_id' => $spare->id,
    ]);

    expect(app(PlaceService::class)->handle($this->group, for: $service)->id)->toBe($spare->id);
});

/**
 * A node whose disk fills inside the quarter is a node that will be somebody's
 * incident before the service placed on it is out of its first billing cycle.
 */
it('avoids a node the daily series says is filling up', function (): void {
    $filling = Server::factory()->inGroup($this->group)->create(['name' => 'filling']);
    $steady = Server::factory()->inGroup($this->group)->create(['name' => 'steady']);

    $series = function (Server $server, array $values): void {
        $node = ($this->node)($server);

        foreach (array_values($values) as $index => $value) {
            ResourceMetricDayFactory::new()
                ->forNode($node)
                ->on(CarbonImmutable::now()->subDays(count($values) - $index)->toDateString(), $value)
                ->create(['metric' => MetricKind::DiskUsed->value, 'unit' => MetricUnit::Bytes->value]);
        }

        ($this->reading)($server, MetricKind::DiskUsed, (float) end($values), MetricUnit::Bytes);
        ($this->reading)($server, MetricKind::DiskTotal, 1000.0, MetricUnit::Bytes);
    };

    // Both at 500 of 1000 today: identical on every factor but the trend.
    $series($filling, [420.0, 432.0, 445.0, 457.0, 470.0, 482.0, 495.0, 500.0]);
    $series($steady, [498.0, 499.0, 498.0, 499.0, 500.0, 499.0, 500.0, 500.0]);

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($steady->id);
});

it('writes down why, in numbers rather than in a sentence', function (): void {
    $server = Server::factory()->inGroup($this->group)->create(['name' => 'web-7']);
    ($this->reading)($server, MetricKind::CpuUtilisation, 0.25);

    $service = ($this->service)();

    app(PlaceService::class)->handle($this->group, for: $service);

    $placement = ServicePlacement::query()->sole();

    expect($placement->service_id)->toBe($service->id)
        ->and($placement->server_id)->toBe($server->id)
        // Copied at the moment of the decision: the node may be renamed or
        // decommissioned, and a record that cannot say what was chosen is not
        // a record.
        ->and($placement->server_name)->toBe('web-7')
        ->and($placement->strategy)->toBe(PlacementStrategy::Scored)
        ->and($placement->candidates)->toBe(1)
        ->and($placement->score)->toBeGreaterThan(0.0);

    $cpu = ($placement->factors ?? [])[PlacementFactor::Cpu->value] ?? null;

    expect($cpu)->not->toBeNull()
        ->and($cpu['measure'])->toBe(0.25)
        ->and($cpu['weight'])->toBe(PlacementFactor::Cpu->weight());

    // Nothing in the row is a sentence anybody would have to translate.
    foreach ($placement->factors ?? [] as $reading) {
        expect($reading)->toHaveKeys(['score', 'weight', 'measure']);
    }
});

/**
 * The readings are worth having whatever chose. "The group is set to fewest
 * accounts, and this is what the disk was doing at the time" is the sentence an
 * operator wants six weeks later.
 */
it('records the reason for the other strategies too', function (): void {
    $this->group->update(['placement_strategy' => PlacementStrategy::LeastAccounts->value]);

    $server = Server::factory()->inGroup($this->group)->create();
    ($this->reading)($server, MetricKind::CpuUtilisation, 0.4);

    app(PlaceService::class)->handle($this->group, for: ($this->service)());

    $placement = ServicePlacement::query()->sole();

    expect($placement->strategy)->toBe(PlacementStrategy::LeastAccounts)
        ->and($placement->factors)->toHaveKey(PlacementFactor::Cpu->value);
});

/**
 * Two placements are two rows. Where a service has lived is the useful part.
 */
it('appends rather than rewriting when a service is placed again', function (): void {
    $first = Server::factory()->inGroup($this->group)->create(['name' => 'first']);
    $service = ($this->service)();

    app(PlaceService::class)->handle($this->group, for: $service);

    $first->forceFill(['status' => 'maintenance'])->save();
    Server::factory()->inGroup($this->group)->create(['name' => 'second']);

    app(PlaceService::class)->handle($this->group, for: $service);

    expect(ServicePlacement::query()->where('service_id', $service->id)->count())->toBe(2)
        ->and(ServicePlacement::query()->oldest('decided_at')->pluck('server_name')->all())
        ->toBe(['first', 'second']);
});

/**
 * Two nodes that score identically are separated by accounts, not by whichever
 * ULID sorts first. Nothing else would catch this: the sort key is a string, and
 * a key that puts the id in front of the second measure still sorts, still looks
 * deterministic, and silently stops reading half of itself.
 */
it('breaks a tie on accounts rather than on the identifier', function (): void {
    // Created first, so its ULID sorts first: under a key that reads the
    // identifier before the second measure, this is the node that would win.
    $busy = Server::factory()->inGroup($this->group)->create(['name' => 'busy', 'weight' => 2]);
    $quiet = Server::factory()->inGroup($this->group)->create(['name' => 'quiet', 'weight' => 1]);

    ($this->reading)($busy, MetricKind::CpuUtilisation, 0.5);
    ($this->reading)($quiet, MetricKind::CpuUtilisation, 0.5);

    // Two accounts on a node of weight two is one per unit of weight, and so is
    // one account on a node of weight one - so the accounts *factor* ties as
    // well and the scores are identical. The raw counts are not.
    Service::factory()->count(2)->create([
        'organization_id' => $busy->organization_id,
        'server_id' => $busy->id,
    ]);

    Service::factory()->create([
        'organization_id' => $quiet->organization_id,
        'server_id' => $quiet->id,
    ]);

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($quiet->id);
});

/**
 * An installation with no monitoring at all gets the dull correct default
 * rather than an arbitrary node: every candidate answers the same factors,
 * every score ties, and fewest accounts decides.
 */
it('falls back to fewest accounts when nothing has ever been measured', function (): void {
    $busy = Server::factory()->inGroup($this->group)->create(['name' => 'busy']);
    $quiet = Server::factory()->inGroup($this->group)->create(['name' => 'quiet']);

    Service::factory()->count(3)->create([
        'organization_id' => $busy->organization_id,
        'server_id' => $busy->id,
    ]);

    expect(app(PlaceService::class)->handle($this->group)->id)->toBe($quiet->id);
});
