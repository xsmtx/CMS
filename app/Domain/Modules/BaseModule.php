<?php

declare(strict_types=1);

namespace App\Domain\Modules;

use App\Domain\Access\PermissionDefinition;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\Contracts\Module;
use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Risk\Contracts\RiskEvaluator;
use App\Domain\Tax\Contracts\TaxCalculator;

/**
 * What a module inherits so that it only writes what it provides.
 *
 * An interface with twelve methods is an interface nobody implements twice
 * without resenting it. A module that adds one payment gateway should be
 * one method long, and this is how.
 *
 * Deliberately abstract with no constructor: core builds the entrypoint
 * with `new`, and a module whose construction needed arguments would be a
 * module core had to know something about.
 */
abstract class BaseModule implements Module
{
    /**
     * @return list<PaymentGateway>
     */
    public function gateways(): array
    {
        return [];
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        return [];
    }

    /**
     * @return list<DomainRegistrar>
     */
    public function registrars(): array
    {
        return [];
    }

    /**
     * @return list<DeliversNotifications>
     */
    public function channels(): array
    {
        return [];
    }

    public function riskEvaluator(): ?RiskEvaluator
    {
        return null;
    }

    public function taxCalculator(): ?TaxCalculator
    {
        return null;
    }

    /**
     * @return list<HealthCheck>
     */
    public function healthChecks(): array
    {
        return [];
    }

    /**
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array
    {
        return [];
    }

    /**
     * Providers that can answer a prompt (ADR 0050).
     *
     * A list rather than one, like gateways and unlike the risk evaluator: a
     * seller may reasonably hold a contract with two vendors and want a
     * cheaper model for summaries than for a customer-facing draft. Which one
     * answers a given feature is the installation's setting, not the module's.
     *
     * @return list<AiProvider>
     */
    public function aiProviders(): array
    {
        return [];
    }

    /**
     * @return list<PermissionDefinition>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [];
    }

    /**
     * @return list<Widget>
     */
    public function widgets(): array
    {
        return [];
    }

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [];
    }

    public function boot(ModuleContext $context): void
    {
        // Most modules have nothing to do here. The ones that do keep their
        // own handle on the context rather than being handed it again.
    }
}
