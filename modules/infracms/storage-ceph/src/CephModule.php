<?php

declare(strict_types=1);

namespace InfraCMS\StorageCeph;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * A Ceph cluster, as a module.
 *
 * Read only, like every storage adapter in this product will be:
 * `Capability::StorageVolumeWrite` exists and this package declares none of
 * it. Creating and destroying RBD images is provisioning, which core already
 * does through a different seam, and destroying one is the most consequential
 * thing a storage API offers.
 *
 * The address is configuration and the token is not, exactly as
 * `monitoring-prometheus` and `network-fortigate` have it: the address is a
 * setting an operator would read out over the telephone, and the token lives
 * in the vault under `storage/token/ceph`, written from the Adapters screen
 * and read **at the moment of the call**, so a rotation takes effect without
 * restarting a queue.
 *
 * It has never talked to a real Ceph cluster.
 */
final class CephModule extends BaseModule
{
    private string $baseUrl = '';

    private bool $verifyTls = true;

    private int $timeout = 20;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('base_url', 'Ceph manager address', ConfigFieldType::Text, required: true),
            new ConfigField('verify_tls', 'Verify the certificate', ConfigFieldType::Boolean, default: true),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '20'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->baseUrl = rtrim((string) $context->config('base_url', ''), '/');

        /*
         * Default true, and the default is what matters: a boolean absent
         * because nobody has opened the settings screen must not mean "do not
         * check the certificate".
         */
        $this->verifyTls = (bool) $context->config('verify_tls', true);
        $this->timeout = max(1, (int) $context->config('timeout', 20));
    }

    /**
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array
    {
        if ($this->baseUrl === '') {
            return [];
        }

        return [new CephProvider(
            baseUrl: $this->baseUrl,
            token: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('storage', 'token', 'ceph'),
            ),
            verifyTls: $this->verifyTls,
            timeout: $this->timeout,
        )];
    }
}
