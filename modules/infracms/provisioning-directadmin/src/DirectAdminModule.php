<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningDirectAdmin;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * DirectAdmin, as a module.
 *
 * It has **no credential settings**, and that is deliberate: a provisioning
 * module is given a server, and the server row already holds the hostname, the
 * port, the login and the secret. Asking for them again here would be two
 * places to change a password and one of them would be missed.
 *
 * What is left is the handful of choices that are the operator's rather than the
 * server's.
 */
final class DirectAdminModule extends BaseModule
{
    private bool $dedicatedIp = false;

    private bool $notify = false;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('reseller_ip', 'Assign a dedicated IP', ConfigFieldType::Boolean, default: false),
            new ConfigField('notify', 'Let DirectAdmin email the customer', ConfigFieldType::Boolean, default: false),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->dedicatedIp = (bool) $context->config('reseller_ip', false);
        $this->notify = (bool) $context->config('notify', false);
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        return [new DirectAdminProvisioner(
            dedicatedIp: $this->dedicatedIp,
            notifyCustomer: $this->notify,
        )];
    }
}
