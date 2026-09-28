<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\LoadBalancing;

/**
 * Something the load balancer is listening on, and what is behind it (§9).
 *
 * A HAProxy backend section, an Nginx upstream, a Traefik service, an Envoy
 * cluster, an F5 virtual server. Every one of them is "an address and port
 * the world reaches, and a list of machines that answer it", which is why
 * one value object covers all five.
 *
 * `tlsExpiresAt` is deliberately **not** here. A certificate on a balancer is
 * a `DeployedCertificate` and belongs to the fleet §8 already built; a second
 * expiry on a second screen is two answers to one question, and they would
 * disagree the week somebody renewed one of them.
 */
final readonly class Listener
{
    /**
     * @param  list<Backend>  $backends
     */
    public function __construct(
        /** The balancer's own identifier, stable across runs. */
        public string $key,
        public string $name,
        public array $backends = [],
        public ?string $address = null,
        public ?int $port = null,
        /** `http`, `https`, `tcp` — the balancer's own word. */
        public ?string $protocol = null,
        public ?int $activeConnections = null,
        public ?float $requestsPerSecond = null,
    ) {}
}
