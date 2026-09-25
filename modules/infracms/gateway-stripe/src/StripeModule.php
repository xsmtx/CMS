<?php

declare(strict_types=1);

namespace InfraCMS\GatewayStripe;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;

/**
 * Stripe, as a module.
 *
 * It was in core until this release, registered from two environment
 * variables. It is a package now for the reason every adapter in this product
 * eventually becomes one: the day Stripe changes a field, a core release is
 * the wrong unit of shipping and a package version is the right one.
 *
 * **Both keys or neither.** A Stripe configured without its webhook signing
 * secret can take money it can never confirm — the redirect back from a
 * gateway proves nothing (ADR 0024) — so the gateway is not registered at all
 * until both are present, which is exactly what core did before it moved.
 */
final class StripeModule extends BaseModule
{
    private string $secret = '';

    private string $webhookSecret = '';

    private string $apiBase = 'https://api.stripe.com';

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('secret', 'Secret key', ConfigFieldType::Secret, required: true),
            new ConfigField(
                'webhook_secret',
                'Webhook signing secret',
                ConfigFieldType::Secret,
                required: true,
            ),
            new ConfigField(
                'api_base',
                'API base',
                ConfigFieldType::Text,
                required: false,
                default: 'https://api.stripe.com',
            ),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->secret = (string) $context->config('secret', '');
        $this->webhookSecret = (string) $context->config('webhook_secret', '');

        $apiBase = (string) $context->config('api_base', '');
        $this->apiBase = $apiBase === '' ? 'https://api.stripe.com' : $apiBase;
    }

    /**
     * @return list<PaymentGateway>
     */
    public function gateways(): array
    {
        if ($this->secret === '' || $this->webhookSecret === '') {
            return [];
        }

        return [new StripeGateway(
            secret: $this->secret,
            webhookSecret: $this->webhookSecret,
            apiBase: $this->apiBase,
        )];
    }
}
