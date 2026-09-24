<?php

declare(strict_types=1);

namespace InfraCMS\GatewayPayPal;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;

/**
 * PayPal, as a module.
 *
 * The webhook id is **required**, not optional. PayPal verifies a notification
 * by being asked about it, and the question includes the id of the webhook it
 * was sent to; without one there is no way to tell a real notification from a
 * posted one, and a gateway that believed either would be a gateway anybody
 * could mark invoices paid through.
 */
final class PayPalModule extends BaseModule
{
    private string $clientId = '';

    private string $clientSecret = '';

    private string $webhookId = '';

    private bool $sandbox = false;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('client_id', 'Client ID', ConfigFieldType::Secret, required: true),
            new ConfigField('client_secret', 'Client secret', ConfigFieldType::Secret, required: true),
            new ConfigField('webhook_id', 'Webhook ID', ConfigFieldType::Text, required: true),
            new ConfigField('sandbox', 'Sandbox', ConfigFieldType::Boolean, required: false, default: false),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->clientId = (string) $context->config('client_id', '');
        $this->clientSecret = (string) $context->config('client_secret', '');
        $this->webhookId = (string) $context->config('webhook_id', '');
        $this->sandbox = (bool) $context->config('sandbox', false);
    }

    /**
     * @return list<PaymentGateway>
     */
    public function gateways(): array
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->webhookId === '') {
            return [];
        }

        return [new PayPalGateway(
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            webhookId: $this->webhookId,
            apiBase: $this->sandbox
                ? 'https://api-m.sandbox.paypal.com'
                : 'https://api-m.paypal.com',
        )];
    }
}
