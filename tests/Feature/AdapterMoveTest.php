<?php

declare(strict_types=1);

use App\Domain\Modules\ModuleState;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * The release that moves Stripe, cPanel and Namecheap out of core.
 *
 * **This is a migration, not a deletion** (`modules-and-marketplace-plan.md`
 * §2). An installation crossing it must find its gateway still taking
 * payments: a release that silently stopped taking money would be the worst
 * upgrade this product could ship.
 *
 * The migration is driven directly rather than through `artisan migrate`,
 * because the suite's database has already run it — with no adapter
 * configured, which is the other case worth asserting.
 */
function runAdapterMove(): void
{
    /** @var object{up: callable} $migration */
    $migration = require database_path('migrations/2026_10_09_000100_move_core_adapters_into_modules.php');

    $migration->up();
}

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    app(ModuleCatalogue::class)->forget();
    app(ActiveModules::class)->forget();

    // What an installation that never configured them looks like.
    config()->set('platform.billing.gateways.stripe.secret');
    config()->set('platform.billing.gateways.stripe.webhook_secret');
    config()->set('platform.domains.registrars.namecheap.username');
    config()->set('platform.domains.registrars.namecheap.api_key');
    config()->set('platform.provisioning.modules.cpanel.enabled', false);
});

it('carries a configured Stripe across into the package', function (): void {
    config()->set('platform.billing.gateways.stripe.secret', 'sk_test_carried');
    config()->set('platform.billing.gateways.stripe.webhook_secret', 'whsec_carried');

    runAdapterMove();

    $record = ModuleRecord::query()->where('slug', 'gateway-stripe')->first();

    expect($record)->not->toBeNull()
        ->and($record->state)->toBe(ModuleState::Enabled)
        ->and($record->config['secret'] ?? null)->toBe('sk_test_carried')
        ->and($record->config['webhook_secret'] ?? null)->toBe('whsec_carried');

    // And the gateway an invoice screen asks for is there.
    $keys = array_map(
        static fn (object $gateway): string => $gateway->key(),
        app(ActiveModules::class)->gateways(),
    );

    expect($keys)->toContain('stripe');
});

it('leaves an installation that never configured Stripe with no package', function (): void {
    runAdapterMove();

    expect(ModuleRecord::query()->where('slug', 'gateway-stripe')->exists())->toBeFalse();
});

/**
 * Half a Stripe is not a Stripe. Without the signing secret a payment can be
 * taken and never confirmed, which is why the service provider refused to
 * register it — and why the migration refuses to carry it.
 */
it('does not carry a Stripe with only one of its keys', function (): void {
    config()->set('platform.billing.gateways.stripe.secret', 'sk_test_carried');

    runAdapterMove();

    expect(ModuleRecord::query()->where('slug', 'gateway-stripe')->exists())->toBeFalse();
});

it('carries Namecheap with the address it was allow-listed under', function (): void {
    config()->set('platform.domains.registrars.namecheap.username', 'apiuser');
    config()->set('platform.domains.registrars.namecheap.api_key', 'key-carried');
    config()->set('platform.domains.registrars.namecheap.client_ip', '203.0.113.7');

    runAdapterMove();

    $record = ModuleRecord::query()->where('slug', 'registrar-namecheap')->first();

    expect($record)->not->toBeNull()
        ->and($record->state)->toBe(ModuleState::Enabled)
        ->and($record->config['client_ip'] ?? null)->toBe('203.0.113.7');

    $keys = array_map(
        static fn (object $registrar): string => $registrar->key(),
        app(ActiveModules::class)->registrars(),
    );

    expect($keys)->toContain('namecheap');
});

/**
 * cPanel carries the other way: it was registered unless an installation
 * turned it off, so silence means adopt it.
 */
it('carries cPanel unless the installation had switched it off', function (): void {
    config()->set('platform.provisioning.modules.cpanel.enabled', true);

    runAdapterMove();

    expect(ModuleRecord::query()->where('slug', 'provisioning-cpanel')->first()?->state)
        ->toBe(ModuleState::Enabled);
});

it('runs twice without complaining', function (): void {
    config()->set('platform.billing.gateways.stripe.secret', 'sk_test_carried');
    config()->set('platform.billing.gateways.stripe.webhook_secret', 'whsec_carried');

    runAdapterMove();
    runAdapterMove();

    expect(ModuleRecord::query()->where('slug', 'gateway-stripe')->count())->toBe(1);
});
