<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * The capability areas handoff #2 §27 asks for, one member per contract.
 *
 * Twenty-three of them, named for what they are about rather than for a
 * vendor: `Firewall`, not `FortiGate`. A single FortiGate answers `Firewall`,
 * `Switch` and `Routing`; a MikroTik answers two of those; a device that is
 * only a switch exists, and that is why they are separate contracts rather
 * than one `NetworkDeviceProvider` with twenty methods and eleven that throw.
 *
 * Most of these contracts do not exist yet — they arrive with their phase. The
 * enum is complete now on purpose: it is the convention, it is what the
 * Adapters screen groups by, and a list that grows a member per phase is a
 * list whose order and naming drift.
 */
enum AdapterArea: string
{
    case Monitoring = 'monitoring';
    case NetworkDevice = 'network_device';
    case Firewall = 'firewall';
    case Switching = 'switching';
    case Routing = 'routing';
    case Flow = 'flow';
    case Ddos = 'ddos';
    case Dns = 'dns';
    case Certificate = 'certificate';
    case Waf = 'waf';
    case Cdn = 'cdn';
    case Storage = 'storage';
    case DatabaseTelemetry = 'database_telemetry';
    case LoadBalancer = 'load_balancer';
    case Hypervisor = 'hypervisor';
    case Bmc = 'bmc';
    case Pdu = 'pdu';
    case Ups = 'ups';
    case Backup = 'backup';
    case Automation = 'automation';
    case Log = 'log';
    case Secret = 'secret';
    case Metering = 'metering';

    /**
     * Mail operations and reputation (§13).
     *
     * One area rather than two, because the same adapter usually answers
     * both: a mail platform knows its own queue depth and whether its
     * outbound addresses are listed, and splitting them would mean two
     * rows, two credentials and two health checks for one system.
     */
    case Mail = 'mail';

    /**
     * Web applications on somebody's hosting account (§18).
     *
     * The second area §27's list does not name, added for the same reason
     * `Mail` was: §18 asks for a WordPress fleet and none of the
     * twenty-three contracts is about an application living on an account.
     * `Automation` is the nearest and it is not near — that is Ansible and
     * Terraform, configuration a provider applies to its own machines, and
     * filing a customer's plugin list under it would put two unrelated things
     * behind one capability an operator switches on once.
     */
    case Site = 'site';

    /**
     * A container platform, read for hosting-service context (§25).
     *
     * The third area §27's list does not name, and `Automation` is again the
     * nearest and not near: Terraform describes infrastructure a provider
     * intends, and a cluster reports what is running. An operator turning on
     * "automation" has not agreed to let this platform read every namespace
     * they have.
     */
    case Kubernetes = 'kubernetes';

    public function labelKey(): string
    {
        return 'infrastructure.areas.'.$this->value;
    }
}
