<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleState;
use App\Domain\Modules\ModuleType;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * Every official module, installed, configured and enabled.
 *
 * The integrations are the reason this exists rather than a test per package.
 * There will be dozens of them, they are written against documented APIs none of
 * this repository can call, and the mistake they will actually make is not a
 * wrong request shape — it is **declaring something and wiring nothing**: a
 * manifest that names a type, a class that returns an empty array, and a module
 * an operator enables to no effect.
 *
 * That is precisely the class of bug the browser pass found elsewhere in this
 * product, and it is invisible to a unit test of the adapter.
 *
 * So this walks the directory, fills each module's declared configuration with
 * something shaped like a real value, enables it, and asserts the registry it
 * claims to extend actually gained an entry. A module added tomorrow is covered
 * the moment its directory exists, with no test to remember to write.
 *
 * It never makes a network call: enabling registers an adapter, it does not use
 * one.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();
});

/**
 * The official packages, as slugs.
 *
 * Read from disk rather than listed here: a list somebody has to remember to
 * add to is a list that will be missing exactly the module nobody checked.
 *
 * @return list<string>
 */
function officialModules(): array
{
    $root = dirname(__DIR__, 2).'/modules/infracms';

    if (! is_dir($root)) {
        return [];
    }

    $slugs = [];

    foreach ((array) glob($root.'/*/module.json') as $path) {
        if (! is_string($path)) {
            continue;
        }

        /** @var array<string, mixed>|null $manifest */
        $manifest = json_decode((string) file_get_contents($path), true);

        if (is_array($manifest) && is_string($manifest['slug'] ?? null)) {
            $slugs[] = $manifest['slug'];
        }
    }

    sort($slugs);

    return $slugs;
}

/**
 * Something shaped like a real value for every field a module declares.
 *
 * Shaped like one, because several adapters refuse to register when a credential
 * is blank — which is correct behaviour and would make this test assert nothing.
 *
 * @return array<string, mixed>
 */
function plausibleConfig(string $slug): array
{
    $manifest = app(ModuleCatalogue::class)->find($slug);

    $values = [];

    foreach ($manifest?->config ?? [] as $field) {
        $values[$field->key] = match ($field->type) {
            ConfigFieldType::Boolean => false,
            ConfigFieldType::Number => 5,
            ConfigFieldType::Url => 'https://example.test/hook',
            ConfigFieldType::Select => (string) (array_key_first($field->options) ?? 'a'),
            default => 'test-'.$field->key,
        };
    }

    return $values;
}

/**
 * Which registry a module of this type has to have filled.
 */
function registrationKeyFor(ModuleType $type): ?string
{
    return match ($type) {
        ModuleType::PaymentGateway => 'gateway',
        ModuleType::Provisioning => 'provisioning_module',
        ModuleType::Registrar => 'registrar',
        ModuleType::NotificationChannel => 'channel',
        ModuleType::Infrastructure => 'infrastructure_adapter',
        // A widget, a report or an addon extends something whose absence is not
        // a wiring bug, so nothing is asserted beyond it enabling.
        default => null,
    };
}

it('installs, configures and enables every official module', function (string $slug): void {
    $record = app(InstallModule::class)->handle($slug);

    expect($record->state)->toBe(ModuleState::Installed);

    $config = plausibleConfig($slug);

    if ($config !== []) {
        // The manifest's schema, because a module's own `configSchema()` can
        // only be asked of a *running* module and this one is not running yet.
        // That deadlock is the reason the schema is declared in both places.
        $schema = app(ModuleCatalogue::class)->find($slug)?->config ?? [];

        app(SaveModuleConfig::class)->handle($record, $schema, $config);
        $record = $record->fresh() ?? $record;
    }

    app(EnableModule::class)->handle($record);

    $record = ModuleRecord::query()->where('slug', $slug)->sole();

    expect($record->state)->toBe(ModuleState::Enabled, $slug.' did not enable')
        ->and($record->failure_reason)->toBeNull();

    $expected = registrationKeyFor($record->type);

    if ($expected === null) {
        return;
    }

    /** @var array<string, mixed> $capabilities */
    $capabilities = (array) $record->capabilities;

    // The point of the whole file: a manifest that says "payment gateway" and a
    // class that returns no gateway is a module an operator enables to no
    // effect, and nothing else would notice.
    expect($capabilities[$expected] ?? [])
        ->not->toBeEmpty($slug.' declares '.$record->type->value.' and registered no '.$expected);
})->with(fn (): array => officialModules());

it('is actually checking something', function (): void {
    // The guard on the guard. An empty directory would make every case above
    // vacuous, which is how an audit stops auditing.
    expect(count(officialModules()))->toBeGreaterThan(2);
});
