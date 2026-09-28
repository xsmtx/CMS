<?php

declare(strict_types=1);

namespace InfraCMS\HypervisorProxmox;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * A Proxmox cluster, as an infrastructure module.
 *
 * **Not the same thing as `provisioning-proxmox`**, and the two are
 * deliberately separate packages rather than one with two faces. That module
 * creates and destroys the machines this platform sold; this one reads the
 * whole fleet, including every machine nobody here provisioned, and puts it
 * in the graph. They answer different questions, hold different capabilities,
 * and an installation may well want one without the other — a reseller who
 * provisions through Proxmox has no business reading the provider's cluster.
 *
 * The address and the token **id** are configuration; the token secret is
 * not. That is the same split every adapter in this product makes, with one
 * difference: Proxmox's identifier is `user@realm!tokenid`, which is not a
 * secret and is the thing an operator would read out over the telephone when
 * asking somebody to grant it a role.
 *
 * It has never talked to a real Proxmox cluster.
 */
final class ProxmoxFleetModule extends BaseModule
{
    private string $baseUrl = '';

    private string $tokenId = '';

    private bool $includeContainers = true;

    private bool $verifyTls = true;

    private int $timeout = 20;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('base_url', 'Proxmox address', ConfigFieldType::Text, required: true),
            new ConfigField('token_id', 'API token id', ConfigFieldType::Text, required: true),
            new ConfigField('include_containers', 'Include containers', ConfigFieldType::Boolean, default: true),
            new ConfigField('verify_tls', 'Verify the certificate', ConfigFieldType::Boolean, default: true),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '20'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->baseUrl = rtrim((string) $context->config('base_url', ''), '/');
        $this->tokenId = (string) $context->config('token_id', '');
        $this->includeContainers = (bool) $context->config('include_containers', true);
        $this->verifyTls = (bool) $context->config('verify_tls', true);
        $this->timeout = max(1, (int) $context->config('timeout', 20));
    }

    /**
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array
    {
        if ($this->baseUrl === '' || $this->tokenId === '') {
            return [];
        }

        return [new ProxmoxFleetProvider(
            baseUrl: $this->baseUrl,
            tokenId: $this->tokenId,
            secret: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('hypervisor', 'token', 'proxmox'),
            ),
            includeContainers: $this->includeContainers,
            verifyTls: $this->verifyTls,
            timeout: $this->timeout,
        )];
    }
}
