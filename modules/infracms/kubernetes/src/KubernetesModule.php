<?php

declare(strict_types=1);

namespace InfraCMS\Kubernetes;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * A Kubernetes cluster, as a module.
 *
 * **One address, one cluster**, like the FortiGate: a kubeconfig with four
 * contexts in it is four adapters, and a module that tried to be all of them
 * would need the credentials for all of them in one secret.
 *
 * The address is configuration and the service-account token is not — it lives
 * in the vault under `kubernetes/token/<slug>`, written from the Adapters
 * screen and read **at the moment of the call**, so a rotation takes effect
 * without restarting a queue.
 *
 * **Reads only.** Scaling a deployment, draining a node and deleting a
 * namespace are all things the API would accept over this same connection, and
 * every one belongs behind §6's guarded workflow rather than behind a method
 * on this class — the rule the FortiGate stated first and the certificate and
 * DNS contracts repeated.
 *
 * It has never talked to a real cluster.
 */
final class KubernetesModule extends BaseModule
{
    private string $baseUrl = '';

    private string $clusterName = '';

    private bool $verifyTls = true;

    private int $timeout = 15;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('base_url', 'API server address', ConfigFieldType::Text, required: true),
            new ConfigField('cluster_name', 'What to call this cluster', ConfigFieldType::Text),
            new ConfigField('verify_tls', 'Verify the certificate', ConfigFieldType::Boolean, default: true),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '15'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->baseUrl = rtrim((string) $context->config('base_url', ''), '/');
        $this->clusterName = (string) $context->config('cluster_name', '');

        // Default true, and the default is what matters: a boolean absent
        // because nobody opened the settings screen must not mean "do not
        // check the certificate".
        $this->verifyTls = (bool) $context->config('verify_tls', true);
        $this->timeout = max(1, (int) $context->config('timeout', 15));
    }

    /**
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array
    {
        if ($this->baseUrl === '') {
            return [];
        }

        return [new KubernetesProviderAdapter(
            baseUrl: $this->baseUrl,
            clusterName: $this->clusterName,
            token: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('kubernetes', 'token', 'kubernetes'),
            ),
            verifyTls: $this->verifyTls,
            timeout: $this->timeout,
        )];
    }
}
