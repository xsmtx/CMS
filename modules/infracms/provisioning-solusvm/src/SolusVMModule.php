<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningSolusVM;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * SolusVM, as a module.
 *
 * The master's credentials are on the server row; what is here is what SolusVM
 * cannot infer — which hypervisor to build on and from which template. Neither
 * has a safe default: a module that guessed KVM on an OpenVZ master would fail
 * every order, and one that guessed a template would hand a customer whichever
 * operating system happened to be first.
 */
final class SolusVMModule extends BaseModule
{
    private string $virtualization = 'kvm';

    private string $nodeGroup = '';

    private string $template = '';

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('virtualization', 'Virtualisation', ConfigFieldType::Select, required: true, options: [
                'kvm' => 'KVM',
                'openvz' => 'OpenVZ',
                'xen' => 'Xen',
                'xen hvm' => 'Xen HVM',
            ]),
            new ConfigField('node_group', 'Node group', ConfigFieldType::Text),
            new ConfigField('template', 'Template', ConfigFieldType::Text, required: true),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->virtualization = (string) $context->config('virtualization', 'kvm');
        $this->nodeGroup = (string) $context->config('node_group', '');
        $this->template = (string) $context->config('template', '');
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        if ($this->template === '') {
            return [];
        }

        return [new SolusVMProvisioner(
            virtualization: $this->virtualization,
            nodeGroup: $this->nodeGroup,
            template: $this->template,
        )];
    }
}
