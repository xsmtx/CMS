<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningProxmox;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * Proxmox VE, as a module.
 *
 * **A VM is cloned from a template, never built from nothing.** Installing an
 * operating system is minutes of work with a dozen ways to fail, and a
 * provisioning call is not the place for it: the template is prepared once by an
 * operator who can watch it, and every customer's VM is a copy.
 *
 * Which is why `template_id` is required rather than optional. A module that
 * fell back to creating an empty VM would hand a customer a machine that boots
 * to nothing.
 */
final class ProxmoxModule extends BaseModule
{
    private string $node = '';

    private int $templateId = 0;

    private string $storage = 'local-lvm';

    private bool $fullClone = true;

    private string $bridge = 'vmbr0';

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('node', 'Node', ConfigFieldType::Text, required: true),
            new ConfigField('template_id', 'Template VMID', ConfigFieldType::Number, required: true),
            new ConfigField('storage', 'Storage', ConfigFieldType::Text, default: 'local-lvm'),
            new ConfigField('full_clone', 'Full clone', ConfigFieldType::Boolean, default: true),
            new ConfigField('bridge', 'Network bridge', ConfigFieldType::Text, default: 'vmbr0'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->node = (string) $context->config('node', '');
        $this->templateId = (int) $context->config('template_id', 0);
        $this->storage = (string) $context->config('storage', 'local-lvm');
        $this->fullClone = (bool) $context->config('full_clone', true);
        $this->bridge = (string) $context->config('bridge', 'vmbr0');
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        if ($this->node === '' || $this->templateId <= 0) {
            return [];
        }

        return [new ProxmoxProvisioner(
            node: $this->node,
            templateId: $this->templateId,
            storage: $this->storage,
            fullClone: $this->fullClone,
            bridge: $this->bridge,
        )];
    }
}
