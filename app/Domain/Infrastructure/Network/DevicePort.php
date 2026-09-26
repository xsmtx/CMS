<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * One interface on a device, as the device describes it.
 *
 * One value object for both areas rather than two that drift. A network
 * device's inventory lists its interfaces and a switch lists its ports, and
 * they are the same thing seen through two contracts — the switch fills in the
 * VLAN fields and a router leaves them empty. Two classes here would mean two
 * tables, two screens and an operator learning which one a FortiGate lands in.
 *
 * Every field but `name` is nullable, and that is the point: a device reports
 * what its firmware happens to expose, and a null is "this device did not say"
 * rather than a zero this platform invented. A port with no speed is drawn
 * without one.
 *
 * `macAddress` is normalised by whoever builds this - lower case, colon
 * separated - because one device says `AA:BB:CC:DD:EE:FF` and the next says
 * `aabb.ccdd.eeff`, and a graph keyed on the difference holds the same NIC
 * twice.
 */
final readonly class DevicePort
{
    /**
     * @param  list<int>  $taggedVlans  VLAN tags carried on this port, if the
     *                                  device reports trunking. Empty means
     *                                  either an access port or a device that
     *                                  did not say.
     * @param  list<string>  $addresses  The addresses configured on this
     *                                   interface, in CIDR notation, as the
     *                                   device wrote them. Not parsed here:
     *                                   `IpPrefix` refuses a host address
     *                                   where a network was wanted, which is
     *                                   correct for something an operator
     *                                   typed and wrong for something a
     *                                   device said — and a device answering
     *                                   something this platform's parser
     *                                   rejects is a finding rather than a
     *                                   value to swallow.
     */
    public function __construct(
        public string $name,
        public PortState $state = PortState::Unknown,
        public ?string $description = null,
        public ?int $speedMbps = null,
        public ?string $macAddress = null,
        public ?int $untaggedVlan = null,
        public array $taggedVlans = [],
        public array $addresses = [],
    ) {}
}
