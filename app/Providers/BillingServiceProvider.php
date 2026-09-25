<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Gateways\ManualGateway;
use App\Infrastructure\Modules\ActiveModules;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the payment gateways this installation can actually use.
 *
 * A gateway missing its credentials is not registered at all, rather than
 * being offered and then failing at the till. The set an operator sees is
 * the set that works.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GatewayRegistry::class, function (Application $app): GatewayRegistry {
            $registry = new GatewayRegistry;

            if (config('platform.billing.gateways.manual.enabled', true)) {
                $registry->register(new ManualGateway(
                    (string) config('platform.billing.gateways.manual.instructions', ''),
                ));
            }

            /*
             * Stripe is a package now (`gateway-stripe`), and so are cPanel
             * and Namecheap. Core keeps the manual three forever, because
             * "an operator does it by hand" must always be a real answer —
             * and ships no adapter it has never proven against the thing it
             * adapts.
             *
             * Whatever the enabled modules add is asked last, so a module
             * cannot displace an adapter this installation ships with: a
             * registry keys by name, and core has already claimed its own.
             */
            foreach ($app->make(ActiveModules::class)->gateways() as $gateway) {
                $registry->register($gateway);
            }

            return $registry;
        });
    }
}
