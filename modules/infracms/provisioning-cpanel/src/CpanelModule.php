<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningCpanel;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * cPanel/WHM, as a module.
 *
 * No credentials here, and that is the point: a WHM token belongs to a
 * *server*, not to an installation, and the fleet already holds one per
 * machine. What is configurable is how patient this adapter is with a panel
 * that is slow or down — a timeout and a bounded number of retries.
 */
final class CpanelModule extends BaseModule
{
    private int $timeout = 30;

    private int $retries = 2;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '30'),
            new ConfigField('retries', 'Retries', ConfigFieldType::Text, default: '2'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->timeout = max(1, (int) $context->config('timeout', 30));
        $this->retries = max(0, (int) $context->config('retries', 2));
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        return [new CpanelProvisioner(timeout: $this->timeout, retries: $this->retries)];
    }
}
