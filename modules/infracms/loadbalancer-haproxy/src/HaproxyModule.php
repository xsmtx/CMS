<?php

declare(strict_types=1);

namespace InfraCMS\LoadbalancerHaproxy;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * HAProxy, as a module.
 *
 * **The first adapter in this product that ships a write**, and the plan says
 * why: a drain is reversible where a firewall policy is not, so it is a
 * guarded *action* — a permission plus the password challenge — rather than a
 * guarded *change* needing a second person at two in the morning.
 *
 * The Data Plane API authenticates with HTTP basic, not a bearer token, so
 * the split is one line different from the other adapters: **the user is
 * configuration and the password is not.** A user name is a setting an
 * operator would read out over the telephone; the password lives in the vault
 * under `loadbalancer/password/haproxy`, written from the Adapters screen and
 * read at the moment of the call, so a rotation takes effect without
 * restarting a queue.
 *
 * It has never talked to a real HAProxy.
 */
final class HaproxyModule extends BaseModule
{
    private string $baseUrl = '';

    private string $username = '';

    private string $apiVersion = 'v2';

    private bool $verifyTls = true;

    private int $timeout = 15;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('base_url', 'Data Plane API address', ConfigFieldType::Text, required: true),
            new ConfigField('username', 'API user', ConfigFieldType::Text, required: true),
            new ConfigField('api_version', 'API version', ConfigFieldType::Text, default: 'v2'),
            new ConfigField('verify_tls', 'Verify the certificate', ConfigFieldType::Boolean, default: true),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '15'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->baseUrl = rtrim((string) $context->config('base_url', ''), '/');
        $this->username = (string) $context->config('username', '');

        /*
         * Asked rather than guessed. The Data Plane API moved its paths
         * between v2 and v3, and an adapter that probed for the version would
         * make two requests every call to answer a question an operator knows
         * the answer to.
         */
        $version = (string) $context->config('api_version', '');
        $this->apiVersion = $version === 'v3' ? 'v3' : 'v2';

        $this->verifyTls = (bool) $context->config('verify_tls', true);
        $this->timeout = max(1, (int) $context->config('timeout', 15));
    }

    /**
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array
    {
        if ($this->baseUrl === '' || $this->username === '') {
            return [];
        }

        return [new HaproxyProvider(
            baseUrl: $this->baseUrl,
            username: $this->username,
            password: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('loadbalancer', 'password', 'haproxy'),
            ),
            apiVersion: $this->apiVersion,
            verifyTls: $this->verifyTls,
            timeout: $this->timeout,
        )];
    }
}
