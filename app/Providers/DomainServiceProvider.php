<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Domains\Listeners\RegisterOrderedDomains;
use App\Domain\Ordering\Events\OrderPaid;
use App\Infrastructure\Domains\RegistrarRegistry;
use App\Infrastructure\Domains\Registrars\ManualRegistrar;
use App\Infrastructure\Modules\ActiveModules;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the registrars this installation can actually use.
 *
 * The same rule as the gateways and the provisioning modules: one without
 * credentials is not registered, so an operator picking a registrar on a
 * TLD form is picking from the set that works. `manual` is always there,
 * because "an operator registers it at the registrar's own panel" is always
 * possible.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegistrarRegistry::class, function (Application $app): RegistrarRegistry {
            $registry = new RegistrarRegistry;

            $registry->register(new ManualRegistrar);

            // Namecheap is a package now (`registrar-namecheap`). Manual
            // stays here forever: "an operator does it by hand" must always
            // be a real answer.
            //
            // Whatever the enabled modules add is asked last, so a module
            // cannot displace an adapter this installation ships with: a
            // registry keys by name, and core has already claimed its own.
            foreach ($app->make(ActiveModules::class)->registrars() as $registrar) {
                $registry->register($registrar);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        // Registered explicitly, like provisioning's: a listener that
        // starts spending money at a registrar should be visible in a file.
        Event::listen(OrderPaid::class, RegisterOrderedDomains::class);
    }
}
