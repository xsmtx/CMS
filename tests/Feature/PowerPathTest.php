<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\Power\PowerFeed;
use App\Domain\Infrastructure\Power\SensorKind;
use App\Domain\Infrastructure\Relation;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceEdge;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * The power path (§11), driven through the real Server Technology package
 * over faked HTTP.
 *
 * **`Relation::Powers` has existed since Phase A and this is its first
 * caller.** The edge goes from the *outlet*, because two devices on one PDU
 * are usually on different breakers and A-versus-B redundancy is the only
 * question anybody asks of a power diagram.
 *
 * The rest of what is pinned here is the four things about that API a careful
 * read turns up, and the one rule that decides where a sensor's answer goes:
 * a temperature is a number and a leak is a state, and a state stored as 1.0
 * is a chart nobody can read.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();
});

/**
 * What the fake PDU is currently reporting.
 *
 * A static rather than a second `Http::fake()` call: faking twice adds a stub
 * rather than replacing the first.
 */
final class PduState
{
    /** @var list<array<string, mixed>> */
    public static array $outlets = [];

    /** @var array<string, list<array<string, mixed>>> */
    public static array $sensors = [];

    public static int $status = 200;

    /** Whether the PDU has a probe fitted at all. */
    public static bool $probed = true;
}

/**
 * @param  list<array<string, mixed>>  $outlets
 * @param  array<string, list<array<string, mixed>>>  $sensors
 */
function pduAnswers(array $outlets, array $sensors = [], int $status = 200, bool $probed = true): void
{
    PduState::$outlets = $outlets;
    PduState::$sensors = $sensors;
    PduState::$status = $status;
    PduState::$probed = $probed;

    Http::fake([
        'pdu.test/jaws/monitor/outlets*' => fn () => Http::response(PduState::$outlets, PduState::$status),
        'pdu.test/jaws/monitor/units*' => fn () => Http::response([['firmware' => '8.0h']]),
        'pdu.test/jaws/monitor/sensors/*' => function ($request) {
            if (! PduState::$probed) {
                // A PDU with no probe answers 404, which is not a failure.
                return Http::response([], 404);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $kind = basename($path);

            return Http::response(PduState::$sensors[$kind] ?? [], PduState::$status);
        },
        'pdu.test/*' => fn () => Http::response([]),
    ]);
}

function enablePdu(StaffUser $actor, string $feed = 'a'): void
{
    $record = app(InstallModule::class)->handle('pdu-servertech', $actor);
    $manifest = app(ModuleCatalogue::class)->find('pdu-servertech');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['base_url' => 'https://pdu.test', 'username' => 'readonly', 'feed' => $feed, 'verify_tls' => false],
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);
    app(ActiveModules::class)->forget();
}

function discoverPower(): RunSummary
{
    return app(TaskRegistry::class)->resolve(AutomationTask::Power)->handle();
}

it('writes the PDU and its outlets into the graph', function (): void {
    pduAnswers([
        ['id' => 'AA1', 'name' => 'web-1', 'state' => 'On', 'power' => 210, 'current' => 1.8, 'branch_id' => 'A1'],
        ['id' => 'AA2', 'name' => 'Outlet A2', 'state' => 'Off', 'power' => 0],
    ]);

    enablePdu($this->admin);

    expect(discoverPower()->changed)->toBe(1);

    $pdu = ResourceNode::query()->where('kind', 'pdu')->sole();
    $outlets = ResourceNode::query()->where('kind', 'pdu_outlet')->orderBy('node_key')->get();

    expect($outlets)->toHaveCount(2)
        // Which of the rack's two this PDU is comes from configuration: no
        // PDU knows which of a pair it is, and an operator does.
        ->and($outlets[0]->attributes['feed'])->toBe(PowerFeed::A->value)
        ->and($outlets[0]->attributes['breaker'])->toBe('A1')
        ->and($outlets[0]->attributes['on'])->toBeTrue()
        // The socket's label is the only place the device is recorded.
        ->and($outlets[0]->attributes['plugged_in'])->toBe('web-1')
        // A default name means nobody said, and is kept as nothing rather
        // than as a device called `Outlet A2`.
        ->and($outlets[1]->attributes)->not->toHaveKey('plugged_in');

    expect(ResourceEdge::query()->where('from_node_id', $pdu->id)->count())->toBe(2);
});

/**
 * `Relation::Powers` has existed since Phase A and this is its first caller.
 * The edge is from the **outlet**, because two devices on one PDU are usually
 * on different breakers.
 */
it('powers a device the graph already knows about', function (): void {
    pduAnswers([['id' => 'AA1', 'name' => 'web-1', 'state' => 'On']]);

    $machine = app(ResourceGraph::class)->upsertNode(
        organizationId: $this->provider->id,
        kind: 'server',
        nodeKey: 'web-1',
        label: 'web-1',
    );

    enablePdu($this->admin);
    discoverPower();

    $outlet = ResourceNode::query()->where('kind', 'pdu_outlet')->sole();

    $edge = ResourceEdge::query()
        ->where('from_node_id', $outlet->id)
        ->where('to_node_id', $machine->id)
        ->sole();

    expect($edge->relation)->toBe(Relation::Powers);
});

/**
 * A socket labelled `web-3` that this platform has never heard of is a true
 * and useful thing to see. Inventing a node for it would put hardware into
 * the graph on the word of a sticker.
 */
it('keeps a label that names nothing, and writes no edge', function (): void {
    pduAnswers([['id' => 'AA1', 'name' => 'somebody-elses-box', 'state' => 'On']]);

    enablePdu($this->admin);
    discoverPower();

    $outlet = ResourceNode::query()->where('kind', 'pdu_outlet')->sole();

    expect($outlet->attributes['plugged_in'])->toBe('somebody-elses-box')
        ->and(ResourceEdge::query()->where('relation', Relation::Powers->value)->count())->toBe(0);
});

/**
 * `OnWait` is neither on nor off. Reading it as off would make a socket
 * somebody is switching look already dead.
 */
it('reads a transitional outlet state as nothing rather than as off', function (): void {
    pduAnswers([
        ['id' => 'AA1', 'name' => 'a', 'state' => 'OnWait'],
        ['id' => 'AA2', 'name' => 'b', 'state' => 'Off'],
    ]);

    enablePdu($this->admin);
    discoverPower();

    $outlets = ResourceNode::query()->where('kind', 'pdu_outlet')->orderBy('node_key')->get();

    expect($outlets[0]->attributes)->not->toHaveKey('on')
        ->and($outlets[1]->attributes['on'])->toBeFalse();
});

/**
 * Some firmware answers a string with its unit in it, and kilowatts appear on
 * the larger units. A parse that assumed watts would be a thousand times
 * wrong, which is a rack that looks empty.
 */
it('reads power however the PDU wrote it', function (): void {
    pduAnswers([
        ['id' => 'AA1', 'name' => 'a', 'state' => 'On', 'power' => 210],
        ['id' => 'AA2', 'name' => 'b', 'state' => 'On', 'power' => '180 W'],
        ['id' => 'AA3', 'name' => 'c', 'state' => 'On', 'power' => '1.4 kW'],
        ['id' => 'AA4', 'name' => 'd', 'state' => 'On', 'power' => ''],
    ]);

    enablePdu($this->admin);
    discoverPower();

    $watts = ResourceMetric::query()
        ->where('metric', MetricKind::PowerDraw->value)
        ->with('node')
        ->get()
        ->mapWithKeys(fn (ResourceMetric $metric): array => [
            $metric->node?->label ?? '?' => (int) $metric->value,
        ])
        ->all();

    expect($watts)->toBe(['a' => 210, 'b' => 180, 'c' => 1400]);
});

/**
 * A temperature is a number and a leak is a state. A state stored as 1.0 is a
 * chart nobody can read and an alert nobody can word.
 */
it('records a reading as telemetry and a state as an attribute', function (): void {
    pduAnswers([], [
        'temperature' => [['id' => 'T1', 'name' => 'Inlet', 'temperature' => 22.4, 'sensor_id' => 'R14']],
        'humidity' => [['id' => 'H1', 'name' => 'Inlet', 'humidity' => 41.0]],
        'water' => [['id' => 'W1', 'name' => 'Floor', 'status' => 'Alarm']],
    ]);

    enablePdu($this->admin);
    discoverPower();

    $sensors = ResourceNode::query()
        ->where('kind', 'environment_sensor')
        ->get()
        ->keyBy(fn (ResourceNode $node): string => (string) ($node->attributes['sensor'] ?? ''));

    expect($sensors)->toHaveCount(3)
        ->and($sensors[SensorKind::Temperature->value]->attributes['location'])->toBe('R14')
        // The state, on the node.
        ->and($sensors[SensorKind::Leak->value]->attributes['triggered'])->toBeTrue()
        // And not among the readings.
        ->and($sensors[SensorKind::Leak->value]->attributes)->not->toHaveKey('value');

    // `metric` is cast to the enum, so a bare pluck gives enum instances.
    $metrics = ResourceMetric::query()->get()
        ->map(fn (ResourceMetric $metric): string => $metric->metric->value)
        ->all();

    expect($metrics)->toContain(MetricKind::Temperature->value, MetricKind::Humidity->value)
        ->and($metrics)->toHaveCount(2);
});

/**
 * A PDU with no probe answers 404. An adapter that threw would make every
 * sweep on every unprobed PDU look like an outage.
 */
it('is not a failure when a PDU has no probe fitted', function (): void {
    pduAnswers([['id' => 'AA1', 'name' => 'a', 'state' => 'On']], probed: false);

    enablePdu($this->admin);

    $summary = discoverPower();

    expect($summary->failed)->toBe(0)
        ->and($summary->changed)->toBe(1)
        ->and(ResourceNode::query()->where('kind', 'environment_sensor')->count())->toBe(0);
});

/**
 * The worst thing this sweep could do: a PDU that is merely unreachable read
 * as a rack whose power path has gone.
 */
it('retires nothing when the PDU refuses to answer', function (): void {
    pduAnswers([['id' => 'AA1', 'name' => 'a', 'state' => 'On']]);

    enablePdu($this->admin);
    discoverPower();

    expect(ResourceNode::query()->whereIn('kind', ['pdu', 'pdu_outlet'])->count())->toBe(2);

    pduAnswers([], status: 503);

    expect(discoverPower()->failed)->toBe(1)
        ->and(ResourceNode::query()->whereNotNull('retired_at')->count())->toBe(0);
});

/** Run it twice and the second changes nothing (ADR 0031). */
it('changes nothing the second time', function (): void {
    pduAnswers([['id' => 'AA1', 'name' => 'a', 'state' => 'On']]);
    enablePdu($this->admin);

    discoverPower();
    $before = ResourceNode::query()->count();

    discoverPower();

    expect(ResourceNode::query()->count())->toBe($before)
        ->and(ResourceNode::query()->whereNotNull('retired_at')->count())->toBe(0);
});

/** Every feed and every sensor kind an operator reads is named, both ways. */
it('names every feed and sensor kind in both languages', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (PowerFeed::cases() as $feed) {
            expect(__($feed->labelKey()))->not->toBe($feed->labelKey());
        }

        foreach (SensorKind::cases() as $kind) {
            expect(__($kind->labelKey()))->not->toBe($kind->labelKey());
        }
    }

    app()->setLocale('en');
});
