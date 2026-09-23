<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Provisioning\Listeners\StartFulfilment;
use App\Domain\Ordering\Events\OrderPaid;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Infrastructure\Provisioning\Modules\CpanelModule;
use App\Infrastructure\Provisioning\Modules\ManualModule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the provisioning modules this installation can actually use.
 *
 * The same rule as the payment gateways: a module that is not configured is
 * not offered. An operator picking a module on a product form is picking
 * from the set that works, not from a catalogue of possibilities.
 *
 * `manual` is always registered, because "an operator sets it up" is always
 * possible and is the honest default for a product nobody has automated
 * yet.
 */
final class ProvisioningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, function (Application $app): ModuleRegistry {
            $registry = new ModuleRegistry;

            $registry->register(new ManualModule);

            if ((bool) config('platform.provisioning.modules.cpanel.enabled', true)) {
                $registry->register(new CpanelModule(
                    timeout: (int) config('platform.provisioning.timeout', 30),
                    retries: (int) config('platform.provisioning.retries', 2),
                ));
            }

            // And whatever the enabled modules add. Asked last, so a module
            // cannot displace an adapter this installation ships with: a
            // registry keys by name, and core has already claimed its own.
            foreach ($app->make(ActiveModules::class)->provisioningModules() as $module) {
                $registry->register($module);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        // Registered explicitly rather than discovered: a listener that
        // starts creating accounts on somebody's servers should be visible
        // in a file, not inferred from a method signature.
        Event::listen(OrderPaid::class, StartFulfilment::class);
    }
}
