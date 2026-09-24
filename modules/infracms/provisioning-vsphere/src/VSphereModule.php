<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningVSphere;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * VMware, as a module.
 *
 * **It talks to vCenter, not to a bare ESXi host**, and that is a scoping
 * decision rather than an oversight. A standalone ESXi host exposes a small
 * subset of the Automation API and no clone operation at all; cloning, folders,
 * resource pools and datastore placement are vCenter's. A module that pretended
 * otherwise would work on a lab box and fail on every real deployment.
 *
 * The server row is the vCenter appliance: its hostname, the account and the
 * password. What is configured here is where clones come from and where they go.
 */
final class VSphereModule extends BaseModule
{
    private string $template = '';

    private string $folder = '';

    private string $datastore = '';

    private string $resourcePool = '';

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('template', 'Template VM', ConfigFieldType::Text, required: true),
            new ConfigField('folder', 'Folder', ConfigFieldType::Text),
            new ConfigField('datastore', 'Datastore', ConfigFieldType::Text),
            new ConfigField('resource_pool', 'Resource pool', ConfigFieldType::Text),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->template = (string) $context->config('template', '');
        $this->folder = (string) $context->config('folder', '');
        $this->datastore = (string) $context->config('datastore', '');
        $this->resourcePool = (string) $context->config('resource_pool', '');
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        if ($this->template === '') {
            return [];
        }

        return [new VSphereProvisioner(
            template: $this->template,
            folder: $this->folder,
            datastore: $this->datastore,
            resourcePool: $this->resourcePool,
        )];
    }
}
