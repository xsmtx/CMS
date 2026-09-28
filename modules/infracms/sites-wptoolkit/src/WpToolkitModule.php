<?php

declare(strict_types=1);

namespace InfraCMS\SitesWptoolkit;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * WP Toolkit, as a module (§18).
 *
 * The panel's address is configuration and its API token is not: the token
 * lives in the vault under `sites/token/wptoolkit`, written from the Adapters
 * screen and scoped to the organization that owns it — so a reseller's own
 * panel token is not in the provider's module settings.
 *
 * `server_node` is configuration rather than discovery, and deliberately so.
 * The toolkit knows which sites it hosts and has never heard of this
 * platform's node keys; an operator knows which machine the panel runs on.
 * Guessing it from a hostname would hang four hundred customer sites off
 * whichever server matched, which is the mistake `RecordSamples::byHostname()`
 * refuses rather than resolves.
 */
final class WpToolkitModule extends BaseModule
{
    private string $baseUrl = '';

    private ?string $serverNode = null;

    private bool $verifyTls = true;

    private int $timeout = 20;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('base_url', 'Panel address', ConfigFieldType::Text, required: true),
            new ConfigField('server_node', 'The machine this panel runs on', ConfigFieldType::Text),
            new ConfigField('verify_tls', 'Verify the certificate', ConfigFieldType::Boolean, default: true),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '20'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->baseUrl = rtrim((string) $context->config('base_url', ''), '/');

        $node = trim((string) $context->config('server_node', ''));
        $this->serverNode = $node === '' ? null : $node;

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

        return [new WpToolkitProvider(
            baseUrl: $this->baseUrl,
            // A closure rather than a value: the credential is read when the
            // request is made, so rotating it takes effect immediately.
            token: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('sites', 'token', 'wptoolkit'),
            ),
            serverNode: $this->serverNode,
            verifyTls: $this->verifyTls,
            timeout: $this->timeout,
        )];
    }
}
