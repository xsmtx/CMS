<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Branding\InspectTheme;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Branding\Surface;
use App\Domain\Branding\ThemeManifest;
use App\Infrastructure\Branding\Models\ThemeSetting;
use App\Infrastructure\Branding\ThemeRegistry;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Themes are directories on disk, so these tests write directories on disk
 * and remove them again. A fixture in a temporary path would prove the
 * registry can read a path; only the real one proves the precedence chain
 * an operator will actually get.
 */
function themePath(string $slug): string
{
    return base_path('themes/storefront/'.$slug);
}

function writeTheme(string $slug, array $manifest, array $views = []): void
{
    $path = themePath($slug);

    @mkdir($path.'/views', recursive: true);

    file_put_contents(
        $path.'/theme.json',
        json_encode([...['slug' => $slug, 'surfaces' => ['storefront']], ...$manifest], JSON_THROW_ON_ERROR),
    );

    foreach ($views as $name => $contents) {
        file_put_contents($path.'/views/'.$name, $contents);
    }
}

function removeTheme(string $slug): void
{
    $path = themePath($slug);

    if (! is_dir($path)) {
        return;
    }

    foreach ((array) glob($path.'/views/*') as $file) {
        if (is_string($file)) {
            @unlink($file);
        }
    }

    @unlink($path.'/theme.json');
    @rmdir($path.'/views');
    @rmdir($path);
}

function removeOverride(): void
{
    $path = base_path('themes/overrides/storefront');

    foreach ((array) glob($path.'/*') as $file) {
        if (is_string($file)) {
            @unlink($file);
        }
    }

    @rmdir($path);
    @rmdir(base_path('themes/overrides'));
}

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->withoutGlobalScope('organization')
        ->whereNull('parent_id')
        ->sole();

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

afterEach(function (): void {
    removeTheme('aurora');
    removeTheme('aurora-child');
    removeTheme('ancient');
    removeTheme('hostile');
    removeOverride();
});

it('finds the core theme this platform ships', function (): void {
    $core = app(ThemeRegistry::class)->find(Surface::Storefront, 'core');

    expect($core)->toBeInstanceOf(ThemeManifest::class)
        ->and($core?->parent)->toBeNull();
});

it('reads a theme an operator dropped into the directory', function (): void {
    writeTheme('aurora', ['name' => 'Aurora', 'version' => '1.0.0', 'parent' => 'core']);

    $registry = new ThemeRegistry;

    expect($registry->find(Surface::Storefront, 'aurora')?->name)->toBe('Aurora');
});

it('walks a theme, its parent and the core, nearest first', function (): void {
    writeTheme('aurora', ['name' => 'Aurora', 'version' => '1.0.0', 'parent' => 'core']);
    writeTheme('aurora-child', ['name' => 'Aurora Child', 'version' => '1.0.0', 'parent' => 'aurora']);

    $chain = (new ThemeRegistry)->chain(Surface::Storefront, 'aurora-child');

    expect(array_map(static fn (ThemeManifest $m): string => $m->slug, $chain))
        ->toBe(['aurora-child', 'aurora', 'core']);
});

it('resolves a template at the first level that has it, whole', function (): void {
    writeTheme(
        'aurora',
        ['name' => 'Aurora', 'version' => '1.0.0', 'parent' => 'core'],
        ['home.blade.php' => 'AURORA HOME'],
    );

    ThemeSetting::factory()->forOrganization($this->provider)
        ->surface(Surface::Storefront, 'aurora')
        ->create();

    // The child's home page, and everything it did not override still
    // comes from core — which is what makes a core upgrade reach a theme
    // for free.
    $this->get('/')->assertOk()->assertSee('AURORA HOME', false);
    $this->get('/store')->assertOk();
});

it('lets an installation override beat the theme it chose', function (): void {
    writeTheme(
        'aurora',
        ['name' => 'Aurora', 'version' => '1.0.0', 'parent' => 'core'],
        ['home.blade.php' => 'AURORA HOME'],
    );

    @mkdir(base_path('themes/overrides/storefront'), recursive: true);
    file_put_contents(base_path('themes/overrides/storefront/home.blade.php'), 'OVERRIDE HOME');

    ThemeSetting::factory()->forOrganization($this->provider)
        ->surface(Surface::Storefront, 'aurora')
        ->create();

    // One changed file, outside the theme, so it survives the theme being
    // upgraded.
    $this->get('/')->assertOk()->assertSee('OVERRIDE HOME', false)->assertDontSee('AURORA HOME', false);
});

it('falls back to core when the chosen theme is no longer on disk', function (): void {
    ThemeSetting::factory()->forOrganization($this->provider)
        ->surface(Surface::Storefront, 'deleted-yesterday')
        ->create();

    // The operator has a broken choice, not a broken site.
    $this->get('/')->assertOk();
});

it('refuses a theme containing raw PHP, by file', function (): void {
    writeTheme(
        'hostile',
        ['name' => 'Hostile', 'version' => '1.0.0', 'parent' => 'core'],
        ['home.blade.php' => "<?php system('id'); ?>"],
    );

    $problems = app(InspectTheme::class)->problems(Surface::Storefront, 'hostile');

    // A theme is content an operator downloads. One that can execute is a
    // remote-code-execution feature with a friendly name.
    expect($problems)->not->toBeEmpty()
        ->and($problems[0])->toContain('home.blade.php')
        ->and($problems[0])->toContain('raw PHP');
});

it('accepts Blade directives, which are not raw PHP', function (): void {
    writeTheme(
        'aurora',
        ['name' => 'Aurora', 'version' => '1.0.0', 'parent' => 'core'],
        ['home.blade.php' => '@if (true) {{ $brand }} @endif'],
    );

    // They compile under the same escaping and the same restrictions as
    // any other view in this application.
    expect(app(InspectTheme::class)->isSafe(Surface::Storefront, 'aurora'))->toBeTrue();
});

it('refuses a theme built for a platform this is not', function (): void {
    writeTheme('ancient', [
        'name' => 'Ancient',
        'version' => '1.0.0',
        'parent' => 'core',
        'compatibility' => '>=99.0',
    ]);

    $problems = app(InspectTheme::class)->problems(Surface::Storefront, 'ancient');

    expect($problems)->not->toBeEmpty()
        ->and($problems[0])->toContain('compatibility');
});

it('refuses a theme whose parent is not installed', function (): void {
    writeTheme('aurora', ['name' => 'Aurora', 'version' => '1.0.0', 'parent' => 'nowhere']);

    expect(app(InspectTheme::class)->problems(Surface::Storefront, 'aurora')[0])
        ->toContain('not installed');
});

it('skips a manifest that will not parse rather than taking the site down', function (): void {
    @mkdir(themePath('aurora'), recursive: true);
    file_put_contents(themePath('aurora').'/theme.json', '{ not json');

    // One bad theme must not cost every page.
    expect((new ThemeRegistry)->find(Surface::Storefront, 'aurora'))->toBeNull();

    $this->get('/')->assertOk();
});

it('merges a child theme’s settings over its parent’s', function (): void {
    writeTheme('aurora', [
        'name' => 'Aurora',
        'version' => '1.0.0',
        'parent' => 'core',
        'settings' => ['footer_note' => 'Hosted in Frankfurt'],
    ]);

    $settings = (new ThemeRegistry)->settingsFor(Surface::Storefront, 'aurora');

    // Settings merge and templates do not — the one asymmetry in this
    // feature, and the one worth stating.
    expect($settings['footer_note'])->toBe('Hosted in Frankfurt')
        ->and($settings['show_currency_switcher'])->toBeTrue();
});

it('lets staff switch theme, and audits it', function (): void {
    writeTheme('aurora', ['name' => 'Aurora', 'version' => '1.0.0', 'parent' => 'core']);

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/settings/theme', ['surface' => 'storefront', 'theme' => 'aurora'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ThemeSetting::query()->sole()->theme)->toBe('aurora')
        ->and(DB::table('audit_logs')->where('action', 'branding.theme_changed')->count())->toBe(1);
});

it('refuses to switch to a theme that would execute', function (): void {
    writeTheme(
        'hostile',
        ['name' => 'Hostile', 'version' => '1.0.0', 'parent' => 'core'],
        ['home.blade.php' => '<?= shell_exec($_GET["c"]) ?>'],
    );

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/settings/theme', ['surface' => 'storefront', 'theme' => 'hostile'])
        ->assertSessionHasErrors('theme');

    expect(ThemeSetting::query()->count())->toBe(0);
});

it('refuses a theme name that is a path', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/settings/theme', ['surface' => 'storefront', 'theme' => '../../../etc'])
        ->assertSessionHasErrors('theme');
});
