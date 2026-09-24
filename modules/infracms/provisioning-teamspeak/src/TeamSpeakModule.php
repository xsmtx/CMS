<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningTeamSpeak;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * TeamSpeak, as a module.
 *
 * One setting, and it exists because a TeamSpeak product is usually sold by slot
 * count: the package an operator sold is a number, and this is what to use when
 * it is not.
 */
final class TeamSpeakModule extends BaseModule
{
    private int $slots = 10;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('slots', 'Default slots', ConfigFieldType::Number, default: 10),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->slots = max(1, (int) $context->config('slots', 10));
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        return [new TeamSpeakProvisioner(defaultSlots: $this->slots)];
    }
}
