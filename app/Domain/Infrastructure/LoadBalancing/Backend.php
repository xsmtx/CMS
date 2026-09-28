<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\LoadBalancing;

/**
 * One server behind a listener, as the load balancer describes it (§9).
 *
 * **`activeConnections` is why a drain is not instant.** Telling a balancer
 * to stop sending new connections takes a second; waiting for the ones it
 * already has to finish is the part an operator is actually doing, and a
 * screen that did not show the number would make them guess when it is safe
 * to reboot the machine. Null means the balancer did not say, which is not
 * zero — acting on an invented zero is how somebody reboots a server that is
 * still serving.
 *
 * `nodeKey` is the graph node this backend *is*, where the adapter can say —
 * usually the address it is configured with. Linking a backend to the server
 * it runs on is what makes a drain reachable from a maintenance window.
 */
final readonly class Backend
{
    public function __construct(
        /** The balancer's own identifier, stable across runs. */
        public string $key,
        public string $name,
        public BackendState $state = BackendState::Unknown,
        public ?string $address = null,
        public ?int $port = null,
        /** How much traffic this one is meant to take, where weights are used. */
        public ?int $weight = null,
        public ?int $activeConnections = null,
        /** The machine behind it, in the graph's own words, where the adapter knows. */
        public ?string $nodeKey = null,
    ) {}
}
