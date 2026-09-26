<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Network\DeviceConfiguration;
use App\Domain\Infrastructure\Network\DeviceDescription;

/**
 * Something that can say what a network device is and what it is running.
 *
 * The first of §6's four contracts, and the one the other three assume: a
 * firewall, a switch and a router are all network devices first, and an
 * adapter for a FortiGate implements this **and** `FirewallProvider` **and**
 * `SwitchProvider` rather than one interface with eleven methods that throw.
 * That is why the areas are separate in `AdapterArea` and why they are
 * separate here.
 *
 * **One target per call, unlike `MonitoringProvider`.** A monitoring system
 * answers for four hundred hosts in one query because that is what it is for;
 * a device answers about itself. An adapter that wanted to batch would be an
 * adapter for something that is not a device.
 *
 * **Nothing here writes.** `DeviceConfigWrite` and `DeviceFirmwareWrite` exist
 * in `Capability` and have no method yet, on purpose: the write side is the
 * guarded workflow (Request → Validate → Diff → Authorize → Backup → Apply →
 * Verify), and an interface that let something call `apply()` before that
 * workflow existed would be the one shortcut around it. It arrives with the
 * change records, with the backup step in front of it.
 *
 * Both methods throw `DeviceUnreachable` rather than returning a null that
 * every caller would have to remember to check.
 */
interface NetworkDeviceProvider extends InfrastructureAdapter
{
    /**
     * What the device is: model, serial, firmware and its interfaces.
     *
     * This is what topology turns into graph nodes, so it is called on a
     * sweep rather than on a page load.
     *
     * @param  string  $target  A node key.
     */
    public function describe(string $target): DeviceDescription;

    /**
     * The running configuration, as text.
     *
     * **Never logged and never rendered without being asked for**: a device
     * configuration carries SNMP communities, RADIUS secrets and pre-shared
     * keys. The caller's obligation, stated here because this is where the
     * text comes from.
     *
     * @param  string  $target  A node key.
     */
    public function configuration(string $target): DeviceConfiguration;
}
