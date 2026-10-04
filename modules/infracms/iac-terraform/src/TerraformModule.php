<?php

declare(strict_types=1);

namespace InfraCMS\IacTerraform;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * Terraform Cloud and Terraform Enterprise, as a module.
 *
 * The adapter whose write side can destroy an estate, so it ships **with** its
 * write side rather than without one — and that is not a contradiction of the
 * FortiGate's decision. The FortiGate shipped read-only because §6's guarded
 * workflow did not exist yet; it does now, and `InfrastructureAsCodeWriter`
 * has exactly one caller: `ApplyNetworkChange`, from a change somebody asked
 * for with a reason, somebody else approved, and whose state serial is checked
 * against the workspace moments before the run.
 *
 * `writes_enabled` on the adapter row is the gate above all of that, and it is
 * false until an operator turns it on by name.
 *
 * The address and the organization are configuration and the token is not,
 * exactly as `monitoring-prometheus` has it: the token lives in the vault
 * under `automation/token/terraform`, written from the Adapters screen, and
 * read **at the moment of the call** so a rotation takes effect without
 * restarting a queue.
 *
 * It has never talked to a real Terraform installation.
 */
final class TerraformModule extends BaseModule
{
    private string $baseUrl = '';

    private string $organization = '';

    private bool $verifyTls = true;

    private int $timeout = 30;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField(
                'base_url',
                'API address',
                ConfigFieldType::Text,
                default: 'https://app.terraform.io',
            ),
            new ConfigField('organization', 'Terraform organization', ConfigFieldType::Text, required: true),
            new ConfigField('verify_tls', 'Verify the certificate', ConfigFieldType::Boolean, default: true),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '30'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $base = rtrim((string) $context->config('base_url', ''), '/');
        $this->baseUrl = $base === '' ? 'https://app.terraform.io' : $base;

        $this->organization = (string) $context->config('organization', '');

        // Default true, and the default is what matters: a boolean absent
        // because nobody opened the settings screen must not mean "do not
        // check the certificate".
        $this->verifyTls = (bool) $context->config('verify_tls', true);
        $this->timeout = max(1, (int) $context->config('timeout', 30));
    }

    /**
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array
    {
        if ($this->organization === '') {
            return [];
        }

        return [new TerraformProvider(
            baseUrl: $this->baseUrl,
            organization: $this->organization,
            token: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('automation', 'token', 'terraform'),
            ),
            verifyTls: $this->verifyTls,
            timeout: $this->timeout,
        )];
    }
}
