<?php

declare(strict_types=1);

namespace InfraCMS\MonitoringPrometheus;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * Prometheus, as a module.
 *
 * The address is configuration and the credential is not. Everything here is
 * a setting an operator would happily read out over the telephone; the
 * bearer token lives in the vault under `monitoring/token/prometheus`,
 * written from the Adapters screen and scoped to the organization that owns
 * it — so a reseller's Prometheus token is not in the provider's module
 * settings, which is the difference between a per-installation setting and a
 * per-organization credential.
 *
 * The token is read **at the moment of the call**, not at boot, for the same
 * reason `SafeUrl` resolves DNS immediately before a request: what was true
 * when the module booted is not what matters when the request goes out, and
 * a rotated credential must take effect without restarting the queue.
 */
final class PrometheusModule extends BaseModule
{
    private string $baseUrl = '';

    private string $instanceLabel = 'instance';

    private int $staleAfter = 300;

    private int $timeout = 10;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('base_url', 'Prometheus address', ConfigFieldType::Text, required: true),
            new ConfigField(
                'instance_label',
                'Label carrying the host',
                ConfigFieldType::Text,
                default: 'instance',
            ),
            new ConfigField(
                'stale_after',
                'A reading is stale after (seconds)',
                ConfigFieldType::Text,
                default: '300',
            ),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '10'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->baseUrl = rtrim((string) $context->config('base_url', ''), '/');

        $label = (string) $context->config('instance_label', '');
        $this->instanceLabel = $label === '' ? 'instance' : $label;

        $this->staleAfter = max(30, (int) $context->config('stale_after', 300));
        $this->timeout = max(1, (int) $context->config('timeout', 10));
    }

    /**
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array
    {
        if ($this->baseUrl === '') {
            return [];
        }

        return [new PrometheusProvider(
            baseUrl: $this->baseUrl,
            instanceLabel: $this->instanceLabel,
            staleAfter: $this->staleAfter,
            timeout: $this->timeout,
            // A closure rather than a value: the credential is read when the
            // request is made, so rotating it takes effect immediately.
            token: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('monitoring', 'token', 'prometheus'),
            ),
        )];
    }
}
