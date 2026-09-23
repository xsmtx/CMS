<?php

declare(strict_types=1);

namespace App\Application\Modules;

use App\Domain\Access\PermissionDefinition;
use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Modules\Contracts\Module;
use App\Domain\Modules\Exceptions\InvalidModule;
use App\Domain\Modules\ExtensionPoint;
use App\Domain\Modules\ModuleManifest;
use App\Domain\Modules\NavigationItem;
use App\Domain\Modules\Registration;
use App\Domain\Modules\Widget;
use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * What a module says it will do, and whether it is allowed to.
 *
 * Asked once, between constructing the entrypoint and registering anything.
 * Until this has passed, nothing the module returned has reached a registry
 * — so a package that declares itself a report and hands back a payment
 * gateway is refused with nothing to undo.
 *
 * The type check is the load-bearing one. A type is what an operator reads
 * before enabling a package, and a package that can quietly become
 * something else is a package whose type nobody can rely on.
 */
final readonly class InspectModule
{
    /**
     * @throws InvalidModule
     */
    public function handle(ModuleManifest $manifest, Module $module): Registration
    {
        $registration = new Registration;

        foreach ($this->declared($module) as [$point, $keys]) {
            if ($keys === []) {
                continue;
            }

            if (! $manifest->type->permits($point)) {
                throw InvalidModule::extensionPointNotPermitted(
                    $manifest->slug,
                    $manifest->type->value,
                    $point->value,
                );
            }

            foreach ($keys as $key) {
                $registration = $registration->with($point, $key);
            }
        }

        return $registration;
    }

    /**
     * Every extension point the module reaches, with the keys it uses
     * there.
     *
     * A key is what the rest of the platform will store — `services.module`,
     * `payments.gateway`, `domains.registrar` — which is exactly what
     * uninstall has to check against later.
     *
     * @return list<array{0: ExtensionPoint, 1: list<string>}>
     */
    private function declared(Module $module): array
    {
        $risk = $module->riskEvaluator();
        $tax = $module->taxCalculator();

        return [
            [ExtensionPoint::Gateway, array_map(
                static fn (PaymentGateway $gateway): string => $gateway->key(),
                $module->gateways(),
            )],
            [ExtensionPoint::ProvisioningModule, array_map(
                static fn (ProvisioningModule $provisioning): string => $provisioning->key(),
                $module->provisioningModules(),
            )],
            [ExtensionPoint::Registrar, array_map(
                static fn (DomainRegistrar $registrar): string => $registrar->key(),
                $module->registrars(),
            )],
            [ExtensionPoint::Channel, array_map(
                static fn (DeliversNotifications $channel): string => $channel->channel()->value,
                $module->channels(),
            )],
            // A single answer each, so the key is the module itself: two
            // modules answering "is this order risky" would mean whichever
            // was enabled last quietly wins.
            [ExtensionPoint::RiskEvaluator, $risk === null ? [] : [$risk::class]],
            [ExtensionPoint::TaxCalculator, $tax === null ? [] : [$tax::class]],
            [ExtensionPoint::HealthCheck, array_map(
                static fn (HealthCheck $check): string => $check->key(),
                $module->healthChecks(),
            )],
            [ExtensionPoint::Permission, array_map(
                static fn (PermissionDefinition $permission): string => $permission->slug,
                $module->permissions(),
            )],
            [ExtensionPoint::Navigation, array_map(
                static fn (NavigationItem $item): string => $item->path,
                $module->navigation(),
            )],
            [ExtensionPoint::Widget, array_map(
                static fn (Widget $widget): string => $widget->key,
                $module->widgets(),
            )],
        ];
    }
}
