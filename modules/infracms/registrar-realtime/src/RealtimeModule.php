<?php

declare(strict_types=1);

namespace InfraCMS\RegistrarRealtime;

use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;

/**
 * Realtime Register, as a module.
 *
 * The customer handle is required and is not the same thing as the API key: the
 * key says who is calling, the handle says who owns the domains. Getting it
 * wrong registers names under the wrong reseller, which is not something a
 * support ticket fixes quickly.
 */
final class RealtimeModule extends BaseModule
{
    private string $customer = '';

    private string $apiKey = '';

    private bool $sandbox = false;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('customer', 'Customer handle', ConfigFieldType::Text, required: true),
            new ConfigField('api_key', 'API key', ConfigFieldType::Secret, required: true),
            new ConfigField('sandbox', 'Sandbox (OT&E)', ConfigFieldType::Boolean, default: false),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->customer = (string) $context->config('customer', '');
        $this->apiKey = (string) $context->config('api_key', '');
        $this->sandbox = (bool) $context->config('sandbox', false);
    }

    /**
     * @return list<DomainRegistrar>
     */
    public function registrars(): array
    {
        if ($this->customer === '' || $this->apiKey === '') {
            return [];
        }

        return [new RealtimeRegistrar(
            customer: $this->customer,
            apiKey: $this->apiKey,
            apiBase: $this->sandbox
                ? 'https://api.yoursrs-ote.com'
                : 'https://api.yoursrs.com',
        )];
    }
}
