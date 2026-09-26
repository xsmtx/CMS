<?php

declare(strict_types=1);

namespace InfraCMS\NetworkFortigate;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * A FortiGate, as a module.
 *
 * The first adapter in this product whose *write* side could take a network
 * down, which is why this package ships only its read side. Everything a
 * FortiGate can be told to do arrives with §6's guarded workflow, behind a
 * backup and a diff computed against what the box says at the moment of the
 * apply — not behind a method on this class.
 *
 * The address is configuration and the API token is not, exactly as
 * `monitoring-prometheus` has it: the address is a setting an operator would
 * read out over the telephone, and the token lives in the vault under
 * `network/token/fortigate`, written from the Adapters screen and scoped to
 * the organization that owns it. It is read **at the moment of the call**, so
 * a rotation takes effect without restarting a queue.
 *
 * **One adapter object, three contracts.** A FortiGate is a firewall, a switch
 * and a router in one chassis, so `FortigateProvider` implements
 * `NetworkDeviceProvider`, `FirewallProvider`, `SwitchProvider` and
 * `RoutingProvider` — which is the case `AdapterArea` was split for. The
 * registry sees one adapter key and `capabilities()` says all four areas.
 *
 * It has never talked to a real FortiGate.
 */
final class FortigateModule extends BaseModule
{
    private string $baseUrl = '';

    private string $vdom = 'root';

    private bool $verifyTls = true;

    private int $timeout = 15;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('base_url', 'FortiGate address', ConfigFieldType::Text, required: true),
            new ConfigField('vdom', 'VDOM', ConfigFieldType::Text, default: 'root'),
            new ConfigField(
                'verify_tls',
                'Verify the certificate',
                ConfigFieldType::Boolean,
                default: true,
            ),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '15'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->baseUrl = rtrim((string) $context->config('base_url', ''), '/');

        $vdom = (string) $context->config('vdom', '');
        $this->vdom = $vdom === '' ? 'root' : $vdom;

        /*
         * Default true, and the default is what matters: a boolean that is
         * absent because nobody has opened the settings screen must not mean
         * "do not check the certificate". An operator turns verification off
         * deliberately, for a box with its own self-signed certificate on a
         * management network.
         */
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

        return [new FortigateProvider(
            baseUrl: $this->baseUrl,
            token: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('network', 'token', 'fortigate'),
            ),
            vdom: $this->vdom,
            verifyTls: $this->verifyTls,
            timeout: $this->timeout,
        )];
    }
}
