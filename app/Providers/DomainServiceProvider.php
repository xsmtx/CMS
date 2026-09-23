<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Domains\Listeners\RegisterOrderedDomains;
use App\Domain\Domains\RegistrarAccount;
use App\Domain\Ordering\Events\OrderPaid;
use App\Infrastructure\Domains\RegistrarRegistry;
use App\Infrastructure\Domains\Registrars\ManualRegistrar;
use App\Infrastructure\Domains\Registrars\NamecheapRegistrar;
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
        $this->app->singleton(RegistrarRegistry::class, function (): RegistrarRegistry {
            $registry = new RegistrarRegistry;

            $registry->register(new ManualRegistrar);

            $username = config('platform.domains.registrars.namecheap.username');
            $apiKey = config('platform.domains.registrars.namecheap.api_key');

            // Both, or neither. A Namecheap without an API key is a
            // registrar that can take an order and never register it.
            if (is_string($username) && $username !== '' && is_string($apiKey) && $apiKey !== '') {
                $registry->register(new NamecheapRegistrar(
                    account: new RegistrarAccount(
                        username: $username,
                        apiKey: $apiKey,
                        clientIp: config('platform.domains.registrars.namecheap.client_ip'),
                        sandbox: (bool) config('platform.domains.registrars.namecheap.sandbox', false),
                        apiBase: config('platform.domains.registrars.namecheap.api_base'),
                    ),
                    timeout: (int) config('platform.domains.timeout', 30),
                ));
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
