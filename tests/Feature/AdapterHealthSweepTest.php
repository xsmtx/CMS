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
use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * The adapter health sweep Phase A deferred, driven through a real module.
 *
 * Phase A left health as a button and said why: "a sweep polling twenty
 * devices every five minutes before anybody had configured a timeout would be
 * this platform's first denial of service against its own operator". What
 * makes the sweep safe is that it asks a question about rows rather than
 * about the clock (ADR 0031), and paces itself by what each adapter declared.
 *
 * It runs against the real `monitoring-prometheus` package over faked HTTP,
 * rather than against a mock: `ActiveModules` is final, deliberately, and a
 * fake of it would be a fake of the thing under test. The example probe would
 * have done as well, except that enabling it here loads its classes into the
 * process and `ExampleFileProbeTest` asserts — correctly — that reading a
 * manifest does not.
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

    app(ResourceGraph::class)->upsertNode(
        $this->provider->id,
        ResourceKind::Server,
        'node-1',
        'Node one',
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Whether the fake Prometheus is up, changed between sweeps.
 *
 * A static rather than a second `Http::fake()` call: faking twice *adds* a
 * stub rather than replacing the first, so the original answer keeps matching
 * and a test that "changed the answer" changed nothing.
 */
final class PrometheusSwitch
{
    public static bool $up = true;
}

function prometheusAnswers(bool $up): void
{
    PrometheusSwitch::$up = $up;

    Http::fake([
        'prometheus.test/*' => fn () => PrometheusSwitch::$up
            ? Http::response(['status' => 'success', 'data' => ['version' => '2.53.0']])
            : Http::response('gone', 503),
    ]);
}

function enablePrometheus(StaffUser $actor): void
{
    $record = app(InstallModule::class)->handle('monitoring-prometheus', $actor);
    $manifest = app(ModuleCatalogue::class)->find('monitoring-prometheus');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['base_url' => 'https://prometheus.test', 'instance_label' => 'instance'],
        $actor,
    );

    app(EnableModule::class)->handle($record->fresh(), $actor);

    app(ActiveModules::class)->forget();
}

function sweepHealth(): RunSummary
{
    return app(TaskRegistry::class)
        ->resolve(AutomationTask::AdapterHealth)
        ->handle();
}

it('records what the adapter says about itself', function (): void {
    prometheusAnswers(up: true);
    enablePrometheus($this->admin);

    $summary = sweepHealth();

    $row = ResourceAdapter::query()->where('adapter_key', 'prometheus')->sole();

    expect($row->health)->toBe(HealthState::Ok->value)
        ->and($row->health_checked_at)->not->toBeNull()
        ->and($summary->examined)->toBe(1);
});

/**
 * An adapter author should not have to write a try/catch to report that the
 * thing on the other end is not there.
 */
it('writes a failing state when the source cannot be read', function (): void {
    prometheusAnswers(up: false);
    enablePrometheus($this->admin);

    sweepHealth();

    $row = ResourceAdapter::query()->where('adapter_key', 'prometheus')->sole();

    expect($row->health)->toBe(HealthState::Failing->value)
        ->and($row->health_message)->not->toBeNull()
        // The Phase 9 rule: a health message names no configuration, and the
        // address is the configuration here.
        ->and($row->health_message)->not->toContain('prometheus.test');
});

/**
 * A question about rows, not about the clock: an adapter checked a moment ago
 * — by this sweep or by an operator pressing the button — is skipped.
 */
it('leaves alone an adapter that was checked a moment ago', function (): void {
    prometheusAnswers(up: true);
    enablePrometheus($this->admin);

    sweepHealth();

    $checkedAt = ResourceAdapter::query()->where('adapter_key', 'prometheus')->value('health_checked_at');

    $summary = sweepHealth();

    expect($summary->skipped)->toBe(1)
        ->and(ResourceAdapter::query()->where('adapter_key', 'prometheus')->value('health_checked_at'))
        ->toEqual($checkedAt);
});

it('asks again once enough time has passed', function (): void {
    prometheusAnswers(up: true);
    enablePrometheus($this->admin);

    sweepHealth();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));

    // Now the source is gone, which is the state change worth seeing.
    prometheusAnswers(up: false);

    $summary = sweepHealth();

    expect($summary->changed)->toBe(1)
        ->and(ResourceAdapter::query()->where('adapter_key', 'prometheus')->value('health'))
        ->toBe(HealthState::Failing->value);
});

it('skips an adapter an operator has switched off', function (): void {
    prometheusAnswers(up: true);
    enablePrometheus($this->admin);

    sweepHealth();

    ResourceAdapter::query()->where('adapter_key', 'prometheus')->update(['enabled' => false]);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));

    $summary = sweepHealth();

    expect($summary->skipped)->toBe(1)
        ->and($summary->changed)->toBe(0);
});

/**
 * A sweep that reported twenty changes every five minutes would make the one
 * adapter that actually changed impossible to see.
 */
it('only calls it a change when the state moved', function (): void {
    prometheusAnswers(up: true);
    enablePrometheus($this->admin);

    $first = sweepHealth();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));

    $second = sweepHealth();

    expect($first->changed)->toBe(1)
        ->and($second->changed)->toBe(0)
        ->and($second->skipped)->toBe(1);
});
