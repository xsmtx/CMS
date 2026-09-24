<?php

declare(strict_types=1);

namespace App\Domain\Modules\Contracts;

use App\Domain\Access\PermissionDefinition;
use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ModuleContext;
use App\Domain\Modules\NavigationItem;
use App\Domain\Modules\Widget;
use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Risk\Contracts\RiskEvaluator;
use App\Domain\Tax\Contracts\TaxCalculator;

/**
 * Everything a module can be asked for.
 *
 * **Declarative on purpose.** A module answers questions; core does the
 * wiring. There is no service provider handed over and no container: a
 * module cannot register middleware, replace a binding, add a global scope
 * or reorder the pipeline, because it is never given the chance to try.
 *
 * The cost is that a module cannot do something core has not anticipated.
 * That is the trade this SDK makes deliberately — an extension point added
 * later is a normal change, and an extension point that turned out to be
 * "anything at all" cannot be taken back once modules depend on it.
 *
 * Every method returns a list, and `BaseModule` returns empty ones, so a
 * module that provides a single gateway implements a single method.
 *
 * Everything here is a **platform contract**. No method takes or returns an
 * Eloquent model, a facade or a framework class; `app/Domain` has no
 * framework imports at all and an architecture test enforces it. That is
 * what makes it possible to change how any of this is stored without
 * breaking a module that somebody else wrote.
 */
interface Module
{
    /**
     * @return list<PaymentGateway>
     */
    public function gateways(): array;

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array;

    /**
     * @return list<DomainRegistrar>
     */
    public function registrars(): array;

    /**
     * @return list<DeliversNotifications>
     */
    public function channels(): array;

    /**
     * At most one of each: the platform asks a single question about risk
     * and a single question about tax, and two modules answering would mean
     * whichever was enabled last quietly wins.
     */
    public function riskEvaluator(): ?RiskEvaluator;

    public function taxCalculator(): ?TaxCalculator;

    /**
     * @return list<HealthCheck>
     */
    public function healthChecks(): array;

    /**
     * Adapters this module provides: monitoring sources, devices,
     * hypervisors, backup systems.
     *
     * A list rather than one, because a vendor SDK that speaks to firewalls,
     * switches and routers is one package providing three adapters — and an
     * operator allowing writes is deciding about one of them.
     *
     * Each adapter declares its own capabilities, and core narrows them to
     * read-only until the operator says otherwise. A module cannot grant
     * itself write access by declaring it.
     *
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array;

    /**
     * Permissions this module needs, which become real permissions: they
     * appear on the roles screen and are orphaned rather than deleted when
     * the module goes away.
     *
     * @return list<PermissionDefinition>
     */
    public function permissions(): array;

    /**
     * @return list<NavigationItem>
     */
    public function navigation(): array;

    /**
     * @return list<Widget>
     */
    public function widgets(): array;

    /**
     * What this module needs to be told, so that core can draw the form,
     * validate it, encrypt the secrets and never ask the module to render
     * anything.
     *
     * @return list<ConfigField>
     */
    public function configSchema(): array;

    /**
     * Called once when the module is registered, with its own configuration
     * and its own logger.
     *
     * Anything thrown here disables the module with the reason recorded.
     * One bad module must not take an installation down.
     */
    public function boot(ModuleContext $context): void;
}
