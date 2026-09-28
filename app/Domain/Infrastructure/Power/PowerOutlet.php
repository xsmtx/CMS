<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Power;

/**
 * One socket on a PDU, and what is plugged into it (§11).
 *
 * **The edge is from the outlet, not from the PDU**, and that is the whole
 * design. Two devices on one PDU are usually on different breakers, and a
 * platform that could only say "these forty things are on PDU 3" would be
 * unable to answer the one question anybody asks of a power diagram.
 *
 * `deviceKey` is what the PDU has been told is plugged in — an operator typed
 * it into the PDU's own outlet name, or a discovery protocol reported it.
 * Core matches it against the graph and writes an edge only where a node
 * exists; a name that matches nothing stays an attribute, because a socket
 * labelled `web-3` that this platform has never heard of is a true and useful
 * thing to see.
 *
 * `on` is nullable and null means the PDU did not say — not "off". A screen
 * that drew an unreported outlet as switched off would send somebody to power
 * on a machine that is already running.
 */
final readonly class PowerOutlet
{
    public function __construct(
        /** The PDU's own identifier, stable across runs. */
        public string $key,
        public string $name,
        public PowerFeed $feed = PowerFeed::Unknown,
        public ?bool $on = null,
        public ?float $watts = null,
        public ?float $amps = null,
        /** The breaker or line this socket is behind, where the PDU says. */
        public ?string $breaker = null,
        /** What is plugged in, in the PDU's own words. */
        public ?string $deviceKey = null,
    ) {}
}
