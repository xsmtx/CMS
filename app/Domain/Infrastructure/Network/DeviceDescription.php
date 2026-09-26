<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * What a device says it is.
 *
 * The answer to `DeviceInventoryRead`, and the thing topology turns into graph
 * nodes: the device itself, and a node per port under it.
 *
 * **The serial is the identity, and the hostname is not.** A hostname is
 * changed by whoever last configured the box; a serial survives a rename, a
 * firmware upgrade and being racked somewhere else. Where a device reports
 * both, the serial is what a node key should be built from - which is a
 * decision for the caller, because this object only reports what the device
 * said.
 *
 * `firmware` is a string rather than a parsed version: vendors write
 * `v7.4.1,build2463,231110` and `17.09.05.SPA`, and a platform that parsed
 * those would be a platform that got one of them wrong.
 */
final readonly class DeviceDescription
{
    /**
     * @param  list<DevicePort>  $ports
     */
    public function __construct(
        public string $target,
        public ?string $model = null,
        public ?string $serial = null,
        public ?string $firmware = null,
        public ?string $hostname = null,
        public ?int $uptimeSeconds = null,
        public array $ports = [],
    ) {}
}
