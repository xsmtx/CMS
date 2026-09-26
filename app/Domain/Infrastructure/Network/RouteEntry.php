<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * One line of a routing table.
 *
 * The prefix is text as the device wrote it rather than an `IpPrefix`, and
 * that is not laziness. `App\Domain\Network\IpPrefix` refuses a host address
 * where a network was wanted and normalises the spelling - correct for
 * something an operator typed, wrong for something a device said. A router
 * that answers with a prefix this platform's parser rejects is a finding; a
 * parser that swallowed the answer and reported a different prefix would be a
 * routing table that does not match the one on the box.
 *
 * `distance` is the administrative distance and `metric` is the protocol's own
 * cost. Vendors report one, the other or both; a platform that mapped them
 * into a single "priority" would be comparing OSPF's cost with BGP's local
 * preference, which are not the same number.
 */
final readonly class RouteEntry
{
    public function __construct(
        public string $prefix,
        public ?string $nextHop = null,
        /** `static`, `connected`, `ospf`, `bgp`, as the device names it. */
        public ?string $protocol = null,
        public ?string $interface = null,
        public ?int $distance = null,
        public ?int $metric = null,
    ) {}
}
