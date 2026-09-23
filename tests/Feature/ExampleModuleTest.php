<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Modules\DisableModule;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Modules\Exceptions\InvalidModule;
use App\Domain\Modules\ExtensionPoint;
use App\Domain\Modules\ModuleState;
use App\Domain\Modules\ModuleType;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * The shipped example, driven the whole way.
 *
 * `ModuleLifecycleTest` writes fixture packages to prove the rules. This
 * file proves something else: that the module **in this repository that
 * nothing in core references** installs, configures, enables and registers
 * what it says it will. If the SDK ever stops working from outside core,
 * this is the test that fails.
 *
 * It deliberately uses the real `modules/example/status-board` directory
 * rather than a fixture. A worked example that no test runs is an example
 * that rots.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();
});

it('finds the example on disk and reads what it claims without running it', function (): void {
    $manifest = app(ModuleCatalogue::class)->find('status-board');

    expect($manifest)->not->toBeNull()
        ->and($manifest->type)->toBe(ModuleType::AdminWidget)
        ->and($manifest->entrypointClass())->toBe('Example\StatusBoard\StatusBoardModule')
        // The settings form is in the manifest, so an operator can be asked
        // what this module needs before any of it has run.
        ->and($manifest->config)->toHaveCount(3)
        ->and($manifest->config[0]->key)->toBe('url')
        ->and($manifest->config[0]->required)->toBeTrue();

    // Nothing has been loaded: reading a manifest is not consent.
    expect(class_exists('Example\StatusBoard\StatusBoardModule', autoload: false))->toBeFalse();
});

it('installs without running anything, then refuses to enable unconfigured', function (): void {
    $record = app(InstallModule::class)->handle('status-board', $this->admin);

    expect($record->state)->toBe(ModuleState::Installed)
        ->and($record->capabilities)->toBeNull();

    // `url` is required and empty, so enabling is refused with a sentence
    // rather than starting a module that cannot work.
    expect(fn (): ModuleRecord => app(EnableModule::class)->handle($record, $this->admin))
        ->toThrow(InvalidModule::class);

    expect($record->fresh()->state)->toBe(ModuleState::Failed)
        ->and($record->fresh()->failure_reason)->toContain('url');
});

it('enables once configured and registers exactly what it declared', function (): void {
    $record = app(InstallModule::class)->handle('status-board', $this->admin);

    $manifest = app(ModuleCatalogue::class)->find('status-board');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['url' => 'https://example.test/status', 'label' => 'Upstream', 'timeout' => 3],
        $this->admin,
    );

    $enabled = app(EnableModule::class)->handle($record->fresh(), $this->admin);

    expect($enabled->state)->toBe(ModuleState::Enabled)
        ->and($enabled->failure_reason)->toBeNull();

    $registered = $enabled->capabilities ?? [];

    // What it agreed to, written on the row at the moment it agreed — so
    // uninstall can refuse without loading the package again.
    expect(array_keys($registered))->toContain(
        ExtensionPoint::HealthCheck->value,
        ExtensionPoint::Permission->value,
        ExtensionPoint::Widget->value,
        ExtensionPoint::Navigation->value,
    )->and($registered[ExtensionPoint::Gateway->value] ?? null)->toBeNull();
});

it('puts the module into the registries core already owns', function (): void {
    enableStatusBoard($this->admin);

    $runtime = app(ActiveModules::class);

    expect($runtime->find('status-board'))->not->toBeNull();

    $keys = array_map(
        static fn (object $check): string => $check->key(),
        $runtime->healthChecks(),
    );

    expect($keys)->toContain('status-board');

    $permissions = array_map(
        static fn (object $permission): string => $permission->slug,
        $runtime->permissions(),
    );

    expect($permissions)->toContain('status-board.view');
});

/**
 * The health check is the part most likely to be copied by an author, so
 * the two rules it exists to demonstrate are asserted rather than
 * described: it never throws, and it never publishes what it watches.
 */
it('reports a failure without saying what it was watching', function (): void {
    enableStatusBoard($this->admin);

    $check = app(ActiveModules::class)->healthChecks()[0];
    $report = $check->run();

    expect($report->key)->toBe('status-board')
        ->and($report->detail ?? '')->not->toContain('example.test')
        ->and($report->detail ?? '')->not->toContain('https://');
});

it('leaves the registries alone once it is disabled', function (): void {
    $record = enableStatusBoard($this->admin);

    app(DisableModule::class)->handle($record, $this->admin);

    $runtime = app(ActiveModules::class);

    expect($runtime->find('status-board'))->toBeNull()
        ->and($runtime->healthChecks())->toBeEmpty();
});

function enableStatusBoard(StaffUser $actor): ModuleRecord
{
    $record = app(InstallModule::class)->handle('status-board', $actor);
    $manifest = app(ModuleCatalogue::class)->find('status-board');

    app(SaveModuleConfig::class)->handle(
        $record,
        $manifest->config,
        ['url' => 'https://example.test/status', 'label' => 'Upstream', 'timeout' => 3],
        $actor,
    );

    return app(EnableModule::class)->handle($record->fresh(), $actor);
}
