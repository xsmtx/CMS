<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * Something a module can add to the platform.
 *
 * One member per registry core already owns. The enum exists so that "what
 * does this package actually do" has an answer that can be shown to an
 * operator before they enable it, and checked against the type it claims.
 */
enum ModuleCapability: string
{
    case Gateway = 'gateway';
    case ProvisioningModule = 'provisioning_module';
    case Registrar = 'registrar';
    case Channel = 'channel';
    case RiskEvaluator = 'risk_evaluator';
    case TaxCalculator = 'tax_calculator';
    case HealthCheck = 'health_check';
    case Permission = 'permission';
    case Navigation = 'navigation';
    case Widget = 'widget';

    public function labelKey(): string
    {
        return 'modules.capabilities.'.$this->value;
    }
}
