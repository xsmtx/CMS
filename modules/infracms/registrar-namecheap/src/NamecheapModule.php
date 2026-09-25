<?php

declare(strict_types=1);

namespace InfraCMS\RegistrarNamecheap;

use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\RegistrarAccount;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;

/**
 * Namecheap, as a module.
 *
 * **Both the user and the key, or nothing.** A registrar with no API key is a
 * registrar that can take an order and never register the name, which is worse
 * than one that is absent: the order is paid, the customer is told it is in
 * hand, and nothing owns the domain.
 *
 * The whitelisted IP is Namecheap's own requirement rather than ours. Left
 * empty the server's address is sent, which is right on a single-homed box and
 * wrong behind a proxy — so it is a field rather than a guess.
 */
final class NamecheapModule extends BaseModule
{
    private string $username = '';

    private string $apiKey = '';

    private ?string $clientIp = null;

    private bool $sandbox = false;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('username', 'API user', ConfigFieldType::Text, required: true),
            new ConfigField('api_key', 'API key', ConfigFieldType::Secret, required: true),
            new ConfigField('client_ip', 'Whitelisted IP', ConfigFieldType::Text),
            new ConfigField('sandbox', 'Sandbox', ConfigFieldType::Boolean, default: false),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->username = (string) $context->config('username', '');
        $this->apiKey = (string) $context->config('api_key', '');
        $this->sandbox = (bool) $context->config('sandbox', false);

        $clientIp = (string) $context->config('client_ip', '');
        $this->clientIp = $clientIp === '' ? null : $clientIp;
    }

    /**
     * @return list<DomainRegistrar>
     */
    public function registrars(): array
    {
        if ($this->username === '' || $this->apiKey === '') {
            return [];
        }

        return [new NamecheapRegistrar(
            account: new RegistrarAccount(
                username: $this->username,
                apiKey: $this->apiKey,
                clientIp: $this->clientIp,
                sandbox: $this->sandbox,
            ),
        )];
    }
}
