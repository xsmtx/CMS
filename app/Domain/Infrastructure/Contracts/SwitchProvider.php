<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Network\DevicePort;
use App\Domain\Infrastructure\Network\VlanDescriptor;

/**
 * Something that can read a switch's ports and VLANs.
 *
 * `ports()` returns the same `DevicePort` a device's inventory does, with the
 * VLAN fields filled in. One type rather than two that drift: a port is a port
 * whether it was read through the inventory contract or this one, and two
 * classes would mean two tables and an operator learning which one a FortiGate
 * lands in.
 *
 * `vlans()` answers with `VlanDescriptor` — what the device says it has —
 * which is deliberately **not** the `vlans` table an operator filled in. The
 * value of reading a switch is being able to say the two disagree: a VLAN
 * recorded here and missing on the box, or one on the box nobody recorded, is
 * exactly the finding somebody wants.
 */
interface SwitchProvider extends InfrastructureAdapter
{
    /**
     * @param  string  $target  A node key.
     * @return list<DevicePort>
     */
    public function ports(string $target): array;

    /**
     * @param  string  $target  A node key.
     * @return list<VlanDescriptor>
     */
    public function vlans(string $target): array;
}
