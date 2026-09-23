<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Gateways\ManualGateway;
use App\Infrastructure\Billing\Gateways\StripeGateway;
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

            $secret = config('platform.billing.gateways.stripe.secret');
            $webhookSecret = config('platform.billing.gateways.stripe.webhook_secret');

            // Both keys or neither: a Stripe without a webhook secret can
            // take money it can never confirm.
            if (is_string($secret) && $secret !== '' && is_string($webhookSecret) && $webhookSecret !== '') {
                $registry->register(new StripeGateway(
                    secret: $secret,
                    webhookSecret: $webhookSecret,
                    apiBase: (string) config('platform.billing.gateways.stripe.api_base', 'https://api.stripe.com'),
                ));
            }

            // And whatever the enabled modules add. Asked last, so a module
            // cannot displace an adapter this installation ships with: a
            // registry keys by name, and core has already claimed its own.
            foreach ($app->make(ActiveModules::class)->gateways() as $gateway) {
                $registry->register($gateway);
            }

            return $registry;
        });
    }
}
