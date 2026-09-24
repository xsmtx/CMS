<?php

declare(strict_types=1);

namespace InfraCMS\GatewayIyzico;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;

/**
 * İyzico, as a module.
 *
 * Two credentials and a sandbox switch. The base URL is derived from the switch
 * rather than typed, because a live key pointed at the sandbox is a payment
 * nobody takes and a sandbox key pointed at live is a payment nobody can
 * explain — and neither is something to leave to a text field.
 */
final class IyzicoModule extends BaseModule
{
    private string $apiKey = '';

    private string $secretKey = '';

    private bool $sandbox = false;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('api_key', 'API key', ConfigFieldType::Secret, required: true),
            new ConfigField('secret_key', 'Secret key', ConfigFieldType::Secret, required: true),
            new ConfigField('sandbox', 'Sandbox', ConfigFieldType::Boolean, required: false, default: false),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->apiKey = (string) $context->config('api_key', '');
        $this->secretKey = (string) $context->config('secret_key', '');
        $this->sandbox = (bool) $context->config('sandbox', false);
    }

    /**
     * @return list<PaymentGateway>
     */
    public function gateways(): array
    {
        if ($this->apiKey === '' || $this->secretKey === '') {
            return [];
        }

        return [new IyzicoGateway(
            apiKey: $this->apiKey,
            secretKey: $this->secretKey,
            apiBase: $this->sandbox
                ? 'https://sandbox-api.iyzipay.com'
                : 'https://api.iyzipay.com',
        )];
    }
}
