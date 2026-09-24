<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Marketplace\InstallFromMarketplace;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Marketplace\Contracts\MarketplaceClient;
use App\Domain\Marketplace\Exceptions\PackageRefused;
use App\Domain\Modules\ModuleState;
use App\Domain\Modules\ModuleType;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Tests\Support\FakeMarketplaceClient;

/**
 * The marketplace, from the catalogue to a row.
 *
 * Every case drives the **real** verifier, the real unpacker and real zip files
 * on disk, because the whole feature is a sequence of refusals and a test that
 * mocked them would prove only that the happy path works.
 *
 * The ordering in ADR 0047 is what most of this file asserts: nothing is
 * unpacked before it is proven, and nothing runs at all until somebody enables
 * it on another screen.
 */
beforeEach(function (): void {
    $this->withoutVite();

    /*
     * The configuration first, and before anything that resolves a container
     * binding. `ModuleCatalogue` is a singleton that reads the modules path
     * **when it is first built**, and seeding resolves `PermissionRegistry`,
     * which resolves `ActiveModules`, which builds it — so a config set after
     * the seeders is a config the catalogue never sees. `forget()` clears its
     * memo, not its root.
     */
    $this->modules = sys_get_temp_dir().'/infracms-modules-'.bin2hex(random_bytes(6));

    mkdir($this->modules, 0o755, true);

    config()->set('platform.modules.path', $this->modules);
    config()->set('platform.modules.enabled', true);

    $this->vendor = new FakeMarketplaceClient;

    config()->set('platform.marketplace.api_url', 'https://packages.example');
    config()->set('platform.marketplace.public_key_path', $this->vendor->publishKey());

    $this->app->instance(MarketplaceClient::class, $this->vendor);

    // The catalogue is a singleton that reads the modules path when it is
    // built, and the provider's `boot()` builds one. Forgetting the instance
    // is what makes the next resolution read the path set above; `forget()`
    // clears its memo but keeps its root.
    $this->app->forgetInstance(ModuleCatalogue::class);
    $this->app->forgetInstance(ActiveModules::class);

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->owner = StaffUser::factory()->create();
    $this->owner->assignRole(SystemRole::SuperAdmin);
    $this->owner = $this->owner->fresh();
});

afterEach(function (): void {
    removeDirectory($this->modules);
});

function removeDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
        $child = $path.'/'.$entry;

        is_dir($child) ? removeDirectory($child) : @unlink($child);
    }

    @rmdir($path);
}

/**
 * The files a minimal, valid package contains.
 *
 * @return array<string, string>
 */
function packageFiles(string $slug, string $version = '1.0.0'): array
{
    $namespace = 'Vendor\\'.str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));

    return [
        'module.json' => json_encode([
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'type' => 'admin-widget',
            'version' => $version,
            'description' => 'A package for tests.',
            'provider' => 'InfraCMS',
            'sdk' => '*',
            'platform' => '*',
            'namespace' => $namespace,
            'entrypoint' => 'TestModule',
            'dependencies' => [],
            'migrations' => false,
            'translations' => false,
            'config' => [],
        ], JSON_THROW_ON_ERROR),
        'src/TestModule.php' => "<?php\n\nnamespace {$namespace};\n\nfinal class TestModule extends \\App\\Domain\\Modules\\BaseModule {}\n",
        'README.md' => "# {$slug}\n",
    ];
}

// ---------------------------------------------------------------------------
// The happy path, and what it deliberately does not do
// ---------------------------------------------------------------------------

it('fetches a package, unpacks it and writes a row, and runs nothing', function (): void {
    $this->vendor->offer('test-widget', packageFiles('test-widget'));

    $record = app(InstallFromMarketplace::class)->handle('test-widget', $this->owner);

    expect($record->slug)->toBe('test-widget')
        // Installed, never enabled. Enabling is the one moment somebody agrees
        // to run a package (ADR 0038), and it is a different screen.
        ->and($record->state)->toBe(ModuleState::Installed)
        ->and($record->source)->toBe('marketplace')
        ->and($record->origin_digest)->not->toBeNull();

    expect(is_file($this->modules.'/infracms/test-widget/module.json'))->toBeTrue()
        ->and(is_file($this->modules.'/infracms/test-widget/src/TestModule.php'))->toBeTrue();

    // Two audited moments, not one: what was fetched and what was installed.
    expect(AuditLog::query()->where('action', 'marketplace.package.fetched')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'modules.installed')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// The refusals, which are the feature
// ---------------------------------------------------------------------------

it('refuses a package signed by somebody else', function (): void {
    // The check that matters. Everything else catches an accident; this one
    // catches somebody serving a package they did not sign.
    $this->vendor->offer(
        'evil-widget',
        packageFiles('evil-widget'),
        signWith: FakeMarketplaceClient::otherSecretKey(),
    );

    expect(fn (): mixed => app(InstallFromMarketplace::class)->handle('evil-widget', $this->owner))
        ->toThrow(PackageRefused::class, 'not signed');

    expect(is_dir($this->modules.'/infracms/evil-widget'))->toBeFalse()
        ->and(ModuleRecord::query()->count())->toBe(0);
});

it('refuses a package whose bytes changed after it was offered', function (): void {
    $this->vendor->offer('drifted', packageFiles('drifted'));
    $this->vendor->tamper('drifted', 'README.md', 'something else entirely');

    // The digest catches it first, which is the point of checking it first: a
    // truncated mirror and an attack must not produce the same sentence.
    expect(fn (): mixed => app(InstallFromMarketplace::class)->handle('drifted', $this->owner))
        ->toThrow(PackageRefused::class, 'checksum');

    expect(is_dir($this->modules.'/infracms/drifted'))->toBeFalse();
});

it('refuses an archive that would write outside its own directory', function (): void {
    // Zip slip. An archive entry is a path the archive chooses, and
    // `../../../.env` is a valid entry name — which is the whole reason
    // extraction happens entry by entry and only after the signature holds.
    $files = packageFiles('escaper');
    $files['../../../escaped.txt'] = 'owned';

    $this->vendor->offer('escaper', $files);

    expect(fn (): mixed => app(InstallFromMarketplace::class)->handle('escaper', $this->owner))
        ->toThrow(PackageRefused::class, 'outside its own directory');

    expect(is_file(dirname($this->modules).'/escaped.txt'))->toBeFalse()
        ->and(is_dir($this->modules.'/infracms/escaper'))->toBeFalse();
});

it('refuses a package that declares a different slug from the one offered', function (): void {
    // A package free to name itself could install as `gateway-stripe` and
    // inherit the real one's configuration.
    $this->vendor->offer('honest-name', packageFiles('pretender'));

    expect(fn (): mixed => app(InstallFromMarketplace::class)->handle('honest-name', $this->owner))
        ->toThrow(PackageRefused::class);

    expect(ModuleRecord::query()->count())->toBe(0);
});

it('refuses everything when this installation runs no third-party code', function (): void {
    config()->set('platform.modules.enabled', false);

    $this->vendor->offer('test-widget', packageFiles('test-widget'));

    expect(fn (): mixed => app(InstallFromMarketplace::class)->handle('test-widget', $this->owner))
        ->toThrow(PackageRefused::class, 'third-party code');
});

it('refuses to replace a module somebody put there by hand', function (): void {
    $this->vendor->offer('test-widget', packageFiles('test-widget'));

    app(InstallFromMarketplace::class)->handle('test-widget', $this->owner);

    ModuleRecord::query()->where('slug', 'test-widget')->update(['source' => 'disk']);

    expect(fn (): mixed => app(InstallFromMarketplace::class)->handle('test-widget', $this->owner))
        ->toThrow(PackageRefused::class, 'did not come from the marketplace');
});

it('accepts no download at all when there is no packaging key', function (): void {
    config()->set('platform.marketplace.public_key_path', $this->modules.'/nothing-here.pub');

    $this->vendor->offer('test-widget', packageFiles('test-widget'));

    // Not a refusal of this package: a refusal to accept any downloaded package,
    // which is the honest answer when nothing can prove one. There is no flag
    // that turns this off.
    expect(fn (): mixed => app(InstallFromMarketplace::class)->handle('test-widget', $this->owner))
        ->toThrow(PackageRefused::class, 'No packaging key');
});

// ---------------------------------------------------------------------------
// The screen
// ---------------------------------------------------------------------------

it('is the owner\'s screen and nobody else\'s', function (): void {
    $administrator = StaffUser::factory()->create();
    $administrator->assignRole(SystemRole::Administrator);

    $this->actingAs($administrator->fresh(), 'staff')
        ->get('/admin/apps/marketplace')
        ->assertForbidden();

    $this->actingAs($administrator->fresh(), 'staff')
        ->post('/admin/apps/marketplace', ['slug' => 'test-widget'])
        ->assertForbidden();
});

it('lists what is on offer and installs from the screen', function (): void {
    $this->vendor->offer('test-widget', packageFiles('test-widget'), type: ModuleType::AdminWidget);

    $this->actingAs($this->owner, 'staff')
        ->get('/admin/apps/marketplace')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Apps/Marketplace')
            ->has('packages', 1)
            ->where('packages.0.slug', 'test-widget')
            ->where('packages.0.installed', false)
            ->where('state.configured', true)
        );

    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/marketplace', ['slug' => 'test-widget'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(ModuleRecord::query()->where('slug', 'test-widget')->exists())->toBeTrue();

    // And the row now reads as installed rather than available.
    $this->actingAs($this->owner, 'staff')
        ->get('/admin/apps/marketplace')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('packages.0.installed', true));
});

it('turns a refusal into an error on the form, never a 500', function (): void {
    $this->vendor->offer(
        'evil-widget',
        packageFiles('evil-widget'),
        signWith: FakeMarketplaceClient::otherSecretKey(),
    );

    $this->actingAs($this->owner, 'staff')
        ->post('/admin/apps/marketplace', ['slug' => 'evil-widget'])
        ->assertRedirect()
        ->assertSessionHasErrors('slug');

    expect(ModuleRecord::query()->count())->toBe(0);
});

it('shows an empty catalogue rather than an error when nothing is configured', function (): void {
    config()->set('platform.marketplace.api_url');
    $this->app->forgetInstance(MarketplaceClient::class);

    $this->actingAs($this->owner, 'staff')
        ->get('/admin/apps/marketplace')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state.configured', false)->has('packages', 0));
});
