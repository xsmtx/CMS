<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningPlesk;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * Plesk, as a module.
 *
 * One setting, and it is the one Plesk makes unavoidable: a subscription belongs
 * to somebody. Left empty it belongs to whoever the server connection logs in
 * as, which is what a single-tenant server wants; a reseller login is what a
 * multi-tenant one wants, and getting it wrong is a subscription the customer's
 * reseller cannot see.
 */
final class PleskModule extends BaseModule
{
    private string $owner = '';

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('owner_login', 'Create subscriptions under', ConfigFieldType::Text),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->owner = (string) $context->config('owner_login', '');
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        return [new PleskProvisioner(owner: $this->owner)];
    }
}
