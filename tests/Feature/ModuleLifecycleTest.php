<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Modules\DisableModule;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\UninstallModule;
use App\Application\Modules\UpgradeModule;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Modules\ModuleState;
use App\Infrastructure\Access\Models\Permission;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Infrastructure\Provisioning\Models\Service;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Modules are directories on disk with code in them, so these tests write
 * directories on disk with code in them. A fixture built in memory would
 * prove the lifecycle can be called; only a real package proves that
 * nothing runs until an operator says so, and that everything runs when
 * they do.
 */
function modulePath(string $slug): string
{
    return base_path('modules/testing/'.$slug);
}

/**
 * @param  array<string, mixed>  $manifest
 * @param  array<string, string>  $files  Path under the module => contents.
 */
function writeModule(string $slug, array $manifest = [], array $files = []): void
{
    $path = modulePath($slug);

    @mkdir($path.'/src', recursive: true);

    $defaults = [
        'slug' => $slug,
        'name' => 'Testing '.$slug,
        'type' => 'fraud',
        'version' => '1.0.0',
        'namespace' => 'Testing\\'.str_replace('-', '', ucwords($slug, '-')),
        'entrypoint' => 'Entrypoint',
        'sdk' => '*',
        'platform' => '*',
    ];

    file_put_contents(
        $path.'/module.json',
        json_encode([...$defaults, ...$manifest], JSON_THROW_ON_ERROR),
    );

    foreach ($files as $name => $contents) {
        $target = $path.'/'.$name;

        @mkdir(dirname($target), recursive: true);
        file_put_contents($target, $contents);
    }
}

function removeModule(string $slug): void
{
    $path = modulePath($slug);

    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($path);
}

/**
 * The smallest module that does something: one risk evaluator, which is a
 * type core has no implementation of, so registering it is visible.
 */
function riskModuleSource(string $namespace, string $marker = 'hold'): string
{
    return <<<PHP
    <?php

    namespace {$namespace};

    use App\\Domain\\Modules\\BaseModule;
    use App\\Domain\\Risk\\Contracts\\RiskEvaluator;
    use App\\Domain\\Risk\\RiskAssessment;
    use App\\Domain\\Risk\\RiskDecision;
    use App\\Domain\\Risk\\RiskSubject;

    final class Evaluator implements RiskEvaluator
    {
        public function evaluate(RiskSubject \$subject): RiskAssessment
        {
            return new RiskAssessment(RiskDecision::Accept, 0, ['{$marker}']);
        }
    }

    final class Entrypoint extends BaseModule
    {
        public function riskEvaluator(): ?RiskEvaluator
        {
            return new Evaluator;
        }
    }
    PHP;
}

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    app(ModuleCatalogue::class)->forget();
});

afterEach(function (): void {
    foreach (['risky', 'gatewayish', 'liar', 'ancient', 'broken', 'dependent', 'permissive', 'secretive'] as $slug) {
        removeModule($slug);
    }

    @rmdir(base_path('modules/testing'));

    app(ModuleCatalogue::class)->forget();
});

it('sees a module on disk and runs none of it', function (): void {
    writeModule('risky', [], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Risky'),
    ]);

    app(ModuleCatalogue::class)->forget();

    expect(app(ModuleCatalogue::class)->find('risky'))->not->toBeNull()
        // Nothing installed, nothing registered, no class loaded.
        ->and(ModuleRecord::query()->count())->toBe(0)
        ->and(class_exists('Testing\\Risky\\Entrypoint', autoload: false))->toBeFalse();
});

it('installs without registering anything', function (): void {
    writeModule('risky', [], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Risky'),
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(InstallModule::class)->handle('risky', $this->admin);

    expect($record->state)->toBe(ModuleState::Installed)
        // Installing is not consenting: the risk contract still resolves to
        // the platform's own dull default.
        ->and(app(ActiveModules::class)->riskEvaluator())->toBeNull();
});

it('registers what a module provides once it is enabled', function (): void {
    writeModule('risky', [], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Risky', 'from-module'),
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(InstallModule::class)->handle('risky', $this->admin);
    $record = app(EnableModule::class)->handle($record, $this->admin);

    expect($record->state)->toBe(ModuleState::Enabled)
        // Written down at the moment the operator agreed to it.
        ->and($record->capabilities)->toHaveKey('risk_evaluator');

    // Resolved fresh, as the next request would.
    app()->forgetInstance(ActiveModules::class);

    expect(app(ActiveModules::class)->riskEvaluator())->not->toBeNull();
});

it('stops registering the moment it is disabled', function (): void {
    writeModule('risky', [], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Risky'),
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(EnableModule::class)->handle(
        app(InstallModule::class)->handle('risky', $this->admin),
        $this->admin,
    );

    app(DisableModule::class)->handle($record, $this->admin);

    app()->forgetInstance(ActiveModules::class);

    expect($record->fresh()?->state)->toBe(ModuleState::Disabled)
        ->and(app(ActiveModules::class)->riskEvaluator())->toBeNull()
        // Kept, not cleared: it is what uninstall reads later.
        ->and($record->fresh()?->capabilities)->toHaveKey('risk_evaluator');
});

it('refuses a module that claims one type and registers another', function (): void {
    writeModule('liar', ['type' => 'report'], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Liar'),
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(InstallModule::class)->handle('liar', $this->admin);

    expect(fn () => app(EnableModule::class)->handle($record, $this->admin))
        ->toThrow(RuntimeException::class, 'declares itself a [report]');

    expect($record->fresh()?->state)->toBe(ModuleState::Failed);
});

it('refuses a module built against a different SDK, by name', function (): void {
    writeModule('ancient', ['sdk' => '0.1'], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Ancient'),
    ]);

    app(ModuleCatalogue::class)->forget();

    expect(fn () => app(InstallModule::class)->handle('ancient', $this->admin))
        ->toThrow(RuntimeException::class, 'built against SDK [0.1]');

    // Refused before the row, so nothing is left behind to tidy up.
    expect(ModuleRecord::query()->count())->toBe(0);
});

it('refuses a version range nobody can read rather than assuming anything', function (): void {
    writeModule('ancient', ['platform' => '^1.2 || ~2.0'], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Ancient'),
    ]);

    app(ModuleCatalogue::class)->forget();

    expect(fn () => app(InstallModule::class)->handle('ancient', $this->admin))
        ->toThrow(RuntimeException::class, 'cannot read');
});

it('disables a module that throws rather than taking the installation down', function (): void {
    writeModule('broken', [], [
        'src/Entrypoint.php' => <<<'PHP'
        <?php

        namespace Testing\Broken;

        use App\Domain\Modules\BaseModule;
        use App\Domain\Modules\ModuleContext;

        final class Entrypoint extends BaseModule
        {
            public function boot(ModuleContext $context): void
            {
                throw new \RuntimeException('the vendor API key is wrong');
            }
        }
        PHP,
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(InstallModule::class)->handle('broken', $this->admin);

    expect(fn () => app(EnableModule::class)->handle($record, $this->admin))
        ->toThrow(RuntimeException::class, 'the vendor API key is wrong');

    $record = $record->fresh();

    expect($record?->state)->toBe(ModuleState::Failed)
        // The sentence, kept. An operator who gets "it did not work" with
        // nothing attached reinstalls it three times.
        ->and($record?->failure_reason)->toContain('vendor API key');

    // And the rest of the installation still works.
    $this->actingAs($this->admin, 'staff')->get('/admin/customers')->assertOk();
});

it('refuses to enable a module whose dependency is not enabled', function (): void {
    writeModule('dependent', ['dependencies' => ['missing-one']], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Dependent'),
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(InstallModule::class)->handle('dependent', $this->admin);

    expect(fn () => app(EnableModule::class)->handle($record, $this->admin))
        ->toThrow(RuntimeException::class, 'needs [missing-one]');
});

it('upgrades to the version on disk and refuses to go backwards', function (): void {
    writeModule('risky', [], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Risky'),
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(InstallModule::class)->handle('risky', $this->admin);

    writeModule('risky', ['version' => '1.1.0'], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Risky'),
    ]);

    $record = app(UpgradeModule::class)->handle($record, $this->admin);

    expect($record->version)->toBe('1.1.0');

    writeModule('risky', ['version' => '0.9.0'], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Risky'),
    ]);

    expect(fn () => app(UpgradeModule::class)->handle($record->fresh(), $this->admin))
        ->toThrow(RuntimeException::class, 'Upgrades only go forwards');
});

it('uninstalls a module nothing is using', function (): void {
    writeModule('risky', [], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Risky'),
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(EnableModule::class)->handle(
        app(InstallModule::class)->handle('risky', $this->admin),
        $this->admin,
    );

    app(UninstallModule::class)->handle($record, $this->admin);

    expect(ModuleRecord::query()->count())->toBe(0);
});

/**
 * The guard that matters: a module with live services behind it cannot be
 * removed, and the refusal says what is in the way.
 */
it('refuses to uninstall a module something still points at', function (): void {
    $record = ModuleRecord::factory()->enabled()->create([
        'slug' => 'gatewayish',
        'type' => 'provisioning',
        'capabilities' => ['provisioning_module' => ['acme-panel']],
    ]);

    Service::factory()->create(['module' => 'acme-panel']);

    expect(fn () => app(UninstallModule::class)->handle($record, $this->admin))
        ->toThrow(RuntimeException::class, 'service(s) are provisioned by [acme-panel]');

    expect(ModuleRecord::query()->count())->toBe(1);
});

it('never loads the package in order to refuse the uninstall', function (): void {
    // No files on disk at all: the guard reads the row. A guard that had to
    // load a package to find out whether removing it was safe would be
    // running the very thing an operator wants rid of.
    $record = ModuleRecord::factory()->enabled()->create([
        'slug' => 'gatewayish',
        'type' => 'payment-gateway',
        'capabilities' => ['gateway' => ['acme-pay']],
    ]);

    DB::table('payment_methods')->insert([
        'id' => (string) Str::ulid(),
        'organization_id' => $this->admin->organization_id,
        'customer_id' => Customer::factory()->create()->id,
        'gateway' => 'acme-pay',
        'token' => 'pm_'.Str::lower(Str::random(10)),
        'is_default' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(UninstallModule::class)->handle($record, $this->admin))
        ->toThrow(RuntimeException::class, 'stored card(s) belong to [acme-pay]');
});

/**
 * A slug and namespace of its own, and every fixture module here has one:
 * PHP cannot redefine a class, so two tests writing different bodies under
 * one class name would silently run whichever loaded first.
 */
it('lets a module add a permission that behaves like every other', function (): void {
    writeModule('permissive', ['type' => 'addon'], [
        'src/Entrypoint.php' => <<<'PHP'
        <?php

        namespace Testing\Permissive;

        use App\Domain\Access\PermissionDefinition;
        use App\Domain\Access\RoleScope;
        use App\Domain\Modules\BaseModule;

        final class Entrypoint extends BaseModule
        {
            public function permissions(): array
            {
                return [
                    new PermissionDefinition(
                        'permissive.review',
                        'platform',
                        RoleScope::Staff,
                        module: 'permissive',
                    ),
                ];
            }
        }
        PHP,
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(EnableModule::class)->handle(
        app(InstallModule::class)->handle('permissive', $this->admin),
        $this->admin,
    );

    // Resolved fresh, so the registry is built with the module in place.
    app()->forgetInstance(ActiveModules::class);
    app()->forgetInstance(PermissionRegistry::class);

    $registry = app(PermissionRegistry::class);
    app(SyncPermissions::class)->handle($registry);

    expect($registry->has('permissive.review'))->toBeTrue()
        ->and(Permission::query()->where('slug', 'permissive.review')->exists())->toBeTrue();

    app(UninstallModule::class)->handle($record, $this->admin);

    // Orphaned rather than deleted: a role that granted it keeps the grant,
    // and the grant is what explains a historical decision.
    $permission = Permission::query()->where('slug', 'permissive.review')->first();

    expect($permission)->not->toBeNull()
        ->and($permission?->orphaned_at)->not->toBeNull();
});

it('registers nothing at all when the installation does not load modules', function (): void {
    writeModule('risky', [], [
        'src/Entrypoint.php' => riskModuleSource('Testing\\Risky'),
    ]);

    app(ModuleCatalogue::class)->forget();

    config()->set('platform.modules.enabled', false);

    expect(fn () => app(InstallModule::class)->handle('risky', $this->admin))
        ->toThrow(RuntimeException::class, 'does not load modules');

    app()->forgetInstance(ActiveModules::class);

    expect(app(ActiveModules::class)->all())->toBe([]);
});

it('shows the modules screen to the owner and to nobody else', function (): void {
    $owner = StaffUser::factory()->create();
    $owner->assignRole(SystemRole::SuperAdmin);

    writeModule('risky', [], [
        'src/Entrypoint.php' => riskModuleSource('Testing\Risky'),
    ]);

    app(ModuleCatalogue::class)->forget();

    // An administrator runs the business. Enabling a package runs code this
    // platform did not ship, which is the owner's decision.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/apps/modules')
        ->assertForbidden();

    $this->actingAs($owner->fresh(), 'staff')
        ->get('/admin/apps/modules')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Modules/Index')
            ->has('modules', 0)
            // On disk, read, and running none of it.
            ->has('available', 1)
            ->where('available.0.slug', 'risky'));
});

it('says on the screen what an enabled module reached into', function (): void {
    $owner = StaffUser::factory()->create();
    $owner->assignRole(SystemRole::SuperAdmin);
    $owner = $owner->fresh();

    writeModule('risky', [], [
        'src/Entrypoint.php' => riskModuleSource('Testing\Risky'),
    ]);

    app(ModuleCatalogue::class)->forget();

    app(EnableModule::class)->handle(
        app(InstallModule::class)->handle('risky', $owner),
        $owner,
    );

    $this->actingAs($owner, 'staff')
        ->get('/admin/apps/modules')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('modules', 1)
            ->where('modules.0.state', 'enabled')
            // The sentence the whole screen exists for.
            ->where('modules.0.registers.0.point', 'risk_evaluator'));
});

it('never sends a module secret to the browser', function (): void {
    $owner = StaffUser::factory()->create();
    $owner->assignRole(SystemRole::SuperAdmin);
    $owner = $owner->fresh();

    writeModule('secretive', ['type' => 'addon'], [
        'src/Entrypoint.php' => <<<'PHP'
        <?php

        namespace Testing\Secretive;

        use App\Domain\Modules\BaseModule;
        use App\Domain\Modules\ConfigField;
        use App\Domain\Modules\ConfigFieldType;

        final class Entrypoint extends BaseModule
        {
            public function configSchema(): array
            {
                return [
                    new ConfigField('api_key', 'API key', ConfigFieldType::Secret),
                    new ConfigField('endpoint', 'Endpoint'),
                ];
            }
        }
        PHP,
    ]);

    app(ModuleCatalogue::class)->forget();

    $record = app(EnableModule::class)->handle(
        app(InstallModule::class)->handle('secretive', $owner),
        $owner,
    );

    $this->actingAs($owner, 'staff')
        ->put('/admin/apps/modules/secretive/config', [
            'config' => ['api_key' => 'sk_live_do_not_leak', 'endpoint' => 'https://acme.test'],
        ])
        ->assertRedirect();

    expect($record->fresh()?->config['api_key'] ?? null)->toBe('sk_live_do_not_leak');

    $this->actingAs($owner, 'staff')
        ->get('/admin/apps/modules')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // The fact that one is set travels; the value never does.
            ->where('modules.0.config.0.secret', true)
            ->where('modules.0.config.0.isSet', true)
            ->where('modules.0.config.0.value', null))
        ->assertDontSee('sk_live_do_not_leak');

    // And an operator editing the endpoint does not clear the key.
    $this->actingAs($owner, 'staff')
        ->put('/admin/apps/modules/secretive/config', [
            'config' => ['api_key' => '', 'endpoint' => 'https://acme.test/v2'],
        ])
        ->assertRedirect();

    expect($record->fresh()?->config['api_key'] ?? null)->toBe('sk_live_do_not_leak')
        ->and($record->fresh()?->config['endpoint'] ?? null)->toBe('https://acme.test/v2');
});
