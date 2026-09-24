<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * What an adapter can actually do, asked before anything calls it.
 *
 * This is `Provisioning\ModuleCapabilities` generalised, and that class had the
 * right instinct: core offers a suspend button only where suspending means
 * something, so a registrar-style module that cannot suspend is a normal thing
 * rather than a broken one. Everything here follows from taking that seriously
 * across twenty-three areas instead of one.
 *
 * Three rules are encoded in the member list itself.
 *
 * **Read and write are different capabilities, never a flag on one.** §29
 * requires the distinction and this is where it lives. An adapter that can read
 * a firewall's policies and not write them declares exactly that, and core's
 * screens are built from the answer rather than from hope.
 *
 * **A write that cannot be undone is marked as such.** `isHighRisk()` is what
 * puts a typed-phrase confirmation and a recent password in front of a power
 * cycle, reusing Phase 11's `AppConfirm` ladder and Phase 17's
 * `RequireRecentAuthentication` rather than inventing a second idea of danger.
 *
 * **The value is dotted and stable**, because it is written into
 * `resource_adapters.capabilities` and read back after an upgrade. Renaming a
 * member is a migration, so the names were chosen once, here, for all of them.
 */
enum Capability: string
{
    // Monitoring — §14.
    case MetricsRead = 'monitoring.metrics.read';
    case AlertsRead = 'monitoring.alerts.read';
    case AlertsWrite = 'monitoring.alerts.write';

    // Network devices — §6.
    case DeviceInventoryRead = 'network_device.inventory.read';
    case DeviceConfigRead = 'network_device.config.read';
    case DeviceConfigWrite = 'network_device.config.write';
    case DeviceFirmwareWrite = 'network_device.firmware.write';

    case FirewallPolicyRead = 'firewall.policy.read';
    case FirewallPolicyWrite = 'firewall.policy.write';
    case FirewallSessionRead = 'firewall.session.read';

    case SwitchPortRead = 'switching.port.read';
    case SwitchPortWrite = 'switching.port.write';
    case SwitchVlanRead = 'switching.vlan.read';
    case SwitchVlanWrite = 'switching.vlan.write';

    case RouteRead = 'routing.route.read';
    case BgpSessionRead = 'routing.bgp.read';
    case BgpSessionWrite = 'routing.bgp.write';

    // Traffic — §7.
    case FlowSummaryRead = 'flow.summary.read';
    case DdosEventRead = 'ddos.event.read';
    case DdosMitigationWrite = 'ddos.mitigation.write';

    // Delivery — §8.
    case DnsZoneRead = 'dns.zone.read';
    case DnsRecordWrite = 'dns.record.write';
    case DnssecWrite = 'dns.dnssec.write';
    case CertificateRead = 'certificate.inventory.read';
    case CertificateIssueWrite = 'certificate.issue.write';
    case CertificateDeployWrite = 'certificate.deploy.write';
    case WafSummaryRead = 'waf.summary.read';
    case WafRuleWrite = 'waf.rule.write';
    case CdnSummaryRead = 'cdn.summary.read';
    case CdnPurgeWrite = 'cdn.purge.write';

    // Data platform — §9.
    case StorageCapacityRead = 'storage.capacity.read';
    case StorageVolumeWrite = 'storage.volume.write';
    case DatabaseTelemetryRead = 'database_telemetry.metrics.read';
    case LoadBalancerRead = 'load_balancer.backend.read';
    case LoadBalancerDrainWrite = 'load_balancer.drain.write';

    // Compute — §10.
    case VirtualMachineRead = 'hypervisor.machine.read';
    case VirtualMachinePowerWrite = 'hypervisor.power.write';
    case VirtualMachineConfigWrite = 'hypervisor.config.write';
    case SnapshotWrite = 'hypervisor.snapshot.write';
    case MigrationWrite = 'hypervisor.migration.write';

    case BmcSensorRead = 'bmc.sensor.read';
    case BmcPowerWrite = 'bmc.power.write';
    case BmcBootWrite = 'bmc.boot.write';
    case BmcConsoleWrite = 'bmc.console.write';

    // Facility — §11.
    case PduLoadRead = 'pdu.load.read';
    case PduOutletWrite = 'pdu.outlet.write';
    case UpsStatusRead = 'ups.status.read';

    // Protection — §12.
    case BackupStatusRead = 'backup.status.read';
    case BackupRunWrite = 'backup.run.write';
    case RestoreWrite = 'backup.restore.write';

    // Orchestration and telemetry plumbing — §16, §14, §17, §25.
    case AutomationStateRead = 'automation.state.read';
    case AutomationApplyWrite = 'automation.apply.write';
    case LogQueryRead = 'log.query.read';
    case SecretRead = 'secret.value.read';
    case SecretWrite = 'secret.value.write';
    case UsageRead = 'metering.usage.read';

    /**
     * The contract this capability belongs to.
     *
     * A match rather than splitting the value on a dot: the return type is
     * then an `AdapterArea` PHPStan can see, and a member whose value stopped
     * matching its area fails to compile rather than returning null at
     * runtime.
     */
    public function area(): AdapterArea
    {
        return match ($this) {
            self::MetricsRead, self::AlertsRead, self::AlertsWrite => AdapterArea::Monitoring,
            self::DeviceInventoryRead, self::DeviceConfigRead,
            self::DeviceConfigWrite, self::DeviceFirmwareWrite => AdapterArea::NetworkDevice,
            self::FirewallPolicyRead, self::FirewallPolicyWrite,
            self::FirewallSessionRead => AdapterArea::Firewall,
            self::SwitchPortRead, self::SwitchPortWrite,
            self::SwitchVlanRead, self::SwitchVlanWrite => AdapterArea::Switching,
            self::RouteRead, self::BgpSessionRead, self::BgpSessionWrite => AdapterArea::Routing,
            self::FlowSummaryRead => AdapterArea::Flow,
            self::DdosEventRead, self::DdosMitigationWrite => AdapterArea::Ddos,
            self::DnsZoneRead, self::DnsRecordWrite, self::DnssecWrite => AdapterArea::Dns,
            self::CertificateRead, self::CertificateIssueWrite,
            self::CertificateDeployWrite => AdapterArea::Certificate,
            self::WafSummaryRead, self::WafRuleWrite => AdapterArea::Waf,
            self::CdnSummaryRead, self::CdnPurgeWrite => AdapterArea::Cdn,
            self::StorageCapacityRead, self::StorageVolumeWrite => AdapterArea::Storage,
            self::DatabaseTelemetryRead => AdapterArea::DatabaseTelemetry,
            self::LoadBalancerRead, self::LoadBalancerDrainWrite => AdapterArea::LoadBalancer,
            self::VirtualMachineRead, self::VirtualMachinePowerWrite, self::VirtualMachineConfigWrite,
            self::SnapshotWrite, self::MigrationWrite => AdapterArea::Hypervisor,
            self::BmcSensorRead, self::BmcPowerWrite,
            self::BmcBootWrite, self::BmcConsoleWrite => AdapterArea::Bmc,
            self::PduLoadRead, self::PduOutletWrite => AdapterArea::Pdu,
            self::UpsStatusRead => AdapterArea::Ups,
            self::BackupStatusRead, self::BackupRunWrite, self::RestoreWrite => AdapterArea::Backup,
            self::AutomationStateRead, self::AutomationApplyWrite => AdapterArea::Automation,
            self::LogQueryRead => AdapterArea::Log,
            self::SecretRead, self::SecretWrite => AdapterArea::Secret,
            self::UsageRead => AdapterArea::Metering,
        };
    }

    /**
     * Whether this capability changes something out there.
     *
     * Derived from the value rather than listed again, because the value's
     * last segment is `read` or `write` for every member and a second list
     * would be a second place to forget.
     */
    public function isWrite(): bool
    {
        return str_ends_with($this->value, '.write');
    }

    /**
     * Whether a human should have to mean it.
     *
     * The list is short and specific: cutting power, restoring over live data,
     * moving a running machine, pushing a device configuration or firmware,
     * and reading a secret back out of a vault. Each one is either
     * irreversible or is the thing an attacker with a session would want.
     *
     * `SecretRead` is here although it is a read, which is the exception that
     * proves the rule is about consequence rather than about the verb.
     */
    public function isHighRisk(): bool
    {
        return match ($this) {
            self::DeviceConfigWrite, self::DeviceFirmwareWrite, self::FirewallPolicyWrite,
            self::BmcPowerWrite, self::BmcBootWrite, self::VirtualMachinePowerWrite,
            self::MigrationWrite, self::PduOutletWrite, self::RestoreWrite,
            self::StorageVolumeWrite, self::AutomationApplyWrite,
            self::SecretRead, self::SecretWrite => true,
            default => false,
        };
    }

    /*
     * There is deliberately no `labelKey()` here, unlike every other enum in
     * this domain. The value has dots in it, so
     * `__('infrastructure.capabilities.firewall.policy.write')` would ask the
     * translator to walk four levels of nesting, return the key, and read as
     * "untranslated" while looking like it works — exactly what
     * `PermissionNames` exists to stop for permission slugs. The wording lives
     * in one array keyed by the dotted value and is read by
     * `CapabilityNames`.
     */
}
