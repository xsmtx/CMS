<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningHetzner;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * Hetzner Cloud, as a module.
 *
 * A project token rather than a server row: Hetzner Cloud is an account, and the
 * machines it holds are ordered rather than added. The token is masked by the
 * presenter and never leaves `ModuleContext`.
 */
final class HetznerModule extends BaseModule
{
    private string $token = '';

    private string $region = 'fsn1';

    private string $image = 'ubuntu-24.04';

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('token', 'API token', ConfigFieldType::Secret, required: true),
            new ConfigField('region', 'Region', ConfigFieldType::Text, default: 'fsn1'),
            new ConfigField('image', 'Image', ConfigFieldType::Text, default: 'ubuntu-24.04'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->token = (string) $context->config('token', '');
        $this->region = (string) $context->config('region', 'fsn1');
        $this->image = (string) $context->config('image', 'ubuntu-24.04');
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

        return [new HetznerProvisioner(
            token: $this->token,
            region: $this->region,
            image: $this->image,
        )];
    }
}
