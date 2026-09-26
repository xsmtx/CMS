<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Network\DeviceConfiguration;

/**
 * Something that can change a network device's configuration.
 *
 * The most consequential contract in this product, and it arrives last on
 * purpose: `NetworkDeviceProvider` shipped a phase earlier with its write half
 * deliberately absent, because an interface that let something call `apply()`
 * before the guarded workflow existed would have been the one shortcut around
 * it. The workflow exists now, so this does.
 *
 * **Nothing in core calls this except `ApplyNetworkChange`**, and that runs
 * only from a change record that has been requested with a reason, approved by
 * somebody who is not the requester, and backed up. There is no second path
 * and there must not be one.
 *
 * **`writes_enabled` is still the gate above all of it.** An adapter may
 * declare `DeviceConfigWrite` and the row decides whether this installation
 * permits it; `AdapterRegistry::narrow()` makes the capability *absent* rather
 * than refused, so a screen cannot offer a button the platform would then
 * decline. Implementing this interface grants nothing by itself.
 *
 * Separate from `NetworkDeviceProvider` rather than an extension of it,
 * because read and write are different capabilities and always have been
 * (`Capability`, §29). An adapter that can read a firewall's policy and not
 * write it implements one interface and says so.
 *
 * Both methods throw `DeviceUnreachable` rather than returning a null every
 * caller would have to remember to check.
 */
interface NetworkDeviceWriter extends InfrastructureAdapter
{
    /**
     * Put this configuration on the device.
     *
     * The caller has already read the current configuration and compared its
     * fingerprint against the one the diff was built from, immediately before
     * this call — a diff computed at request time and applied an hour later is
     * a diff against a device somebody else has edited. An implementation does
     * not repeat that check and must not skip a change because it believes
     * nothing has altered: deciding that is core's, and an adapter that
     * short-circuited would be an adapter whose idea of "unchanged" differed
     * from the one in the audit record.
     *
     * @param  string  $target  A node key.
     * @param  string  $configuration  The whole intended configuration, in the
     *                                 format `DeviceConfiguration::$format`
     *                                 named when it was read.
     */
    public function applyConfiguration(string $target, string $configuration): void;

    /**
     * Put the backup back.
     *
     * A separate method rather than `applyConfiguration` with the old text,
     * because a rollback is not an apply and a vendor may not treat it as one
     * — a device that takes a configuration in fragments has a restore that is
     * a different operation, and an adapter that pretended otherwise would
     * leave half of the old configuration on the box.
     *
     * @param  string  $target  A node key.
     */
    public function restoreConfiguration(string $target, DeviceConfiguration $backup): void;
}
