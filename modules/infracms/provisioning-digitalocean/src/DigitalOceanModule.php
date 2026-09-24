<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningDigitalOcean;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * DigitalOcean, as a module.
 *
 * The token lives here rather than on a server row, because there is no server:
 * a public cloud is an account, not a machine an operator added to the fleet.
 * A module's configuration is masked by the presenter and never leaves
 * `ModuleContext`, which is a better home for a credential than the environment
 * file the core adapters still use.
 */
final class DigitalOceanModule extends BaseModule
{
    private string $token = '';

    private string $region = 'fra1';

    private string $image = 'ubuntu-24-04-x64';

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('token', 'API token', ConfigFieldType::Secret, required: true),
            new ConfigField('region', 'Region', ConfigFieldType::Text, default: 'fra1'),
            new ConfigField('image', 'Image', ConfigFieldType::Text, default: 'ubuntu-24-04-x64'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->token = (string) $context->config('token', '');
        $this->region = (string) $context->config('region', 'fra1');
        $this->image = (string) $context->config('image', 'ubuntu-24-04-x64');
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        // No token means no registration: an operator choosing a provisioning
        // module on a product form should be choosing from the set that works.
        if ($this->token === '') {
            return [];
        }

        return [new DigitalOceanProvisioner(
            token: $this->token,
            region: $this->region,
            image: $this->image,
        )];
    }
}
