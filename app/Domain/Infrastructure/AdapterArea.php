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

    public function labelKey(): string
    {
        return 'infrastructure.areas.'.$this->value;
    }
}
