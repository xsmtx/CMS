<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\RecordedRun;
use App\Application\Automation\Runs\CollectTelemetry;
use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Infrastructure\ResourceGraph;
use App\Application\Modules\DisableModule;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\RunStatus;
use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Modules\ExtensionPoint;
use App\Domain\Modules\ModuleState;
use App\Domain\Modules\ModuleType;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceMetric;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The adapter seam, driven from outside core.
 *
 * `ExampleModuleTest` does this for the rest of the SDK; this file does it for
 * handoff #2's addition. Nothing in `app/` references
 * `modules/example/file-probe`, so if the capability registry, the collection
 * sweep or the normalizer stops working for somebody else's package, this is the
 * test that fails.
 *
 * It uses the real module directory rather than a fixture, deliberately: a worked
 * example no test runs is an example that rots.
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

    $this->file = storage_path('framework/testing/file-probe-'.uniqid().'.json');

    $graph = app(ResourceGraph::class);

    $this->one = $graph->upsertNode($this->provider->id, ResourceKind::Server, 'node-1', 'Node one');
    $this->two = $graph->upsertNode($this->provider->id, ResourceKind::Server, 'node-2', 'Node two');
});

afterEach(function (): void {
    if (is_string($this->file) && is_file($this->file)) {
        unlink($this->file);
    }
});

function writeProbeFile(string $path, array $resources, ?string $sampledAt = null): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }

    file_put_contents($path, json_encode([
        'sampled_at' => $sampledAt ?? now()->toIso8601String(),
        'resources' => $resources,
    ], JSON_THROW_ON_ERROR));
}

function enableFileProbe(StaffUser $actor, string $path): ModuleRecord
{
    $record = app(InstallModule::class)->handle('file-probe', $actor);
    $manifest = app(ModuleCatalogue::class)->find('file-probe');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['path' => $path, 'stale_after' => 600],
        $actor,
    );

    $enabled = app(EnableModule::class)->handle($record->fresh(), $actor);

    app(ActiveModules::class)->forget();

    return $enabled;
}

it('reads what the module claims without running it', function (): void {
    $manifest = app(ModuleCatalogue::class)->find('file-probe');

    expect($manifest)->not->toBeNull()
        ->and($manifest->type)->toBe(ModuleType::Infrastructure)
        ->and($manifest->entrypointClass())->toBe('Example\FileProbe\FileProbeModule')
        ->and($manifest->config[0]->key)->toBe('path')
        ->and($manifest->config[0]->required)->toBeTrue();

    // Reading a manifest is not consent (ADR 0038).
    expect(class_exists('Example\FileProbe\FileProbeModule', autoload: false))->toBeFalse();
});

it('registers its adapter and writes that down on the row', function (): void {
    writeProbeFile($this->file, ['node-1' => ['cpu_percent' => 40]]);

    $enabled = enableFileProbe($this->admin, $this->file);

    expect($enabled->state)->toBe(ModuleState::Enabled)
        ->and(array_keys($enabled->capabilities ?? []))
        ->toContain(ExtensionPoint::InfrastructureAdapter->value);

    $adapters = app(ActiveModules::class)->adapters();

    expect($adapters)->toHaveCount(1)
        ->and($adapters[0]->key())->toBe('file-probe');
});

it('is read-only until somebody says otherwise, and has nothing to allow', function (): void {
    writeProbeFile($this->file, ['node-1' => ['cpu_percent' => 40]]);
    enableFileProbe($this->admin, $this->file);

    $registered = app(AdapterRegistry::class)->find($this->provider->id, 'file-probe');

    expect($registered)->toBeInstanceOf(RegisteredAdapter::class)
        ->and($registered->row->writes_enabled)->toBeFalse()
        ->and($registered->permitted()->has(Capability::MetricsRead))->toBeTrue()
        // It declares no write capability at all, so there is nothing an
        // operator could switch on. A toggle that did nothing would teach them
        // the toggle means nothing.
        ->and($registered->descriptor->capabilities->writes())->toBeEmpty();
});

it('collects through the sweep, normalizing names and units on the way in', function (): void {
    writeProbeFile($this->file, [
        'node-1' => [
            'cpu_percent' => 40,
            'memory_used' => ['value' => 8, 'unit' => 'gigabytes'],
            'widgets_frobnicated' => 3,
        ],
    ]);

    enableFileProbe($this->admin, $this->file);

    $run = app(RecordedRun::class)->handle(
        AutomationTask::Telemetry,
        app(CollectTelemetry::class),
    );

    expect($run->status)->toBe(RunStatus::Completed);

    $metrics = ResourceMetric::query()->where('resource_node_id', $this->one->id)->get();

    expect($metrics)->toHaveCount(2);

    $byMetric = $metrics->keyBy(static fn (ResourceMetric $row): string => $row->metric->value);

    expect($byMetric[MetricKind::CpuUtilisation->value]->value)->toBe(0.4)
        ->and($byMetric[MetricKind::MemoryUsed->value]->value)->toBe(8_000_000_000.0)
        // The metric nothing here has a name for was counted and dropped, not
        // stored under its own name.
        ->and($byMetric->has('widgets_frobnicated'))->toBeFalse();

    // And the node the file says nothing about is left alone rather than
    // invented or marked healthy.
    expect(ResourceMetric::query()->where('resource_node_id', $this->two->id)->count())->toBe(0);
});

it('reports a failure without saying which file it was reading', function (): void {
    enableFileProbe($this->admin, $this->file.'-that-does-not-exist');

    $registered = app(AdapterRegistry::class)->find($this->provider->id, 'file-probe');

    $health = $registered->checkHealth(app(SecretRedactor::class));

    expect($health->state)->toBe(HealthState::Failing)
        ->and($health->message)->not->toContain('storage')
        ->and($health->message)->not->toContain('.json')
        ->and($registered->row->fresh()->health)->toBe(HealthState::Failing->value);
});

/**
 * The Check now button, over HTTP.
 *
 * `checkHealth()` is tested above through the registry; this drives the
 * endpoint the screen actually calls, because a button whose route nobody has
 * ever requested is a button nobody knows works.
 */
it('checks an adapter from the screen and writes the answer on its row', function (): void {
    writeProbeFile($this->file, ['node-1' => ['cpu_percent' => 40]]);
    enableFileProbe($this->admin, $this->file);

    $registered = app(AdapterRegistry::class)->find($this->provider->id, 'file-probe');

    $this->withoutVite()
        ->actingAs($this->admin, 'staff')
        ->post('/admin/resources/adapters/'.$registered->row->id.'/check')
        ->assertRedirect();

    expect($registered->row->fresh()->health)->toBe(HealthState::Ok->value)
        ->and($registered->row->fresh()->health_checked_at)->not->toBeNull();
});

it('collects nothing once the module is disabled', function (): void {
    writeProbeFile($this->file, ['node-1' => ['cpu_percent' => 40]]);

    $record = enableFileProbe($this->admin, $this->file);

    app(DisableModule::class)->handle($record, $this->admin);
    app(ActiveModules::class)->forget();

    $run = app(RecordedRun::class)->handle(
        AutomationTask::Telemetry,
        app(CollectTelemetry::class),
    );

    expect($run->changed)->toBe(0)
        ->and(ResourceMetric::query()->count())->toBe(0);
});
