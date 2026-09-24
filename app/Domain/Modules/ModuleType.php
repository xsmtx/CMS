<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * What kind of thing a module is.
 *
 * The list is the handoff's, and it is closed on purpose. A type is what an
 * operator reads before installing: it says which part of the platform this
 * package reaches into, and therefore what it could do if it turned out to
 * be hostile.
 *
 * So a type is **checked**, not decorative. A module that declares itself a
 * report and registers a payment gateway is refused — a package that can
 * quietly become something else is a package whose type nobody can rely on,
 * and the type is the only thing most operators will actually read.
 */
enum ModuleType: string
{
    case PaymentGateway = 'payment-gateway';
    case Provisioning = 'provisioning';
    case Registrar = 'registrar';
    case NotificationChannel = 'notification-channel';
    case Fraud = 'fraud';
    case Tax = 'tax';
    case Report = 'report';
    case AdminWidget = 'admin-widget';
    case ClientWidget = 'client-widget';
    case Addon = 'addon';

    /**
     * Reads or guardedly changes infrastructure: a monitoring source, a
     * device, a hypervisor, a backup system.
     *
     * A type of its own rather than `addon`, because the type is the one
     * thing most operators read before installing and "infrastructure" is a
     * sentence about consequence: this package talks to machines.
     */
    case Infrastructure = 'infrastructure';

    public function labelKey(): string
    {
        return 'modules.types.'.str_replace('-', '_', $this->value).'.label';
    }

    public function descriptionKey(): string
    {
        return 'modules.types.'.str_replace('-', '_', $this->value).'.description';
    }

    /**
     * The capabilities a module of this type is allowed to register.
     *
     * `addon` is the escape hatch and says so: a package that genuinely
     * provides several things declares itself an addon, and an operator
     * reading "addon" knows to look at what it actually registers rather
     * than trusting the word.
     *
     * Everything may register a health check, a permission, navigation and
     * a widget — those are how a module reports on itself and how an
     * operator reaches it, and refusing them per type would mean a gateway
     * that cannot say whether it is reachable.
     *
     * @return list<ExtensionPoint>
     */
    public function allows(): array
    {
        $common = [
            ExtensionPoint::HealthCheck,
            ExtensionPoint::Permission,
            ExtensionPoint::Navigation,
            ExtensionPoint::Widget,
        ];

        return match ($this) {
            self::PaymentGateway => [ExtensionPoint::Gateway, ...$common],
            self::Provisioning => [ExtensionPoint::ProvisioningModule, ...$common],
            self::Registrar => [ExtensionPoint::Registrar, ...$common],
            self::NotificationChannel => [ExtensionPoint::Channel, ...$common],
            self::Fraud => [ExtensionPoint::RiskEvaluator, ...$common],
            self::Tax => [ExtensionPoint::TaxCalculator, ...$common],
            self::Infrastructure => [ExtensionPoint::InfrastructureAdapter, ...$common],
            self::Report, self::AdminWidget, self::ClientWidget => $common,
            self::Addon => ExtensionPoint::cases(),
        };
    }

    public function permits(ExtensionPoint $point): bool
    {
        return in_array($point, $this->allows(), strict: true);
    }
}
