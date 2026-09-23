<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * A seam a module can plug into.
 *
 * One member per registry core already owns. The enum exists so that "what
 * does this package actually do" has an answer that can be shown to an
 * operator before they enable it, and checked against the type it claims.
 *
 * Named for the seam rather than for the module's ability, which also
 * keeps it clear of `App\Domain\Provisioning\ModuleCapabilities` — that
 * one describes what a *provisioning* module can do to a hosting account,
 * and two things called capability in one sentence is one too many.
 */
enum ExtensionPoint: string
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
        return 'modules.extension_points.'.$this->value;
    }
}
