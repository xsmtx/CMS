<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Network\BgpSession;
use App\Domain\Infrastructure\Network\RouteEntry;

/**
 * Something that can read a router's table and its BGP neighbours.
 *
 * **`routes()` is asked for a prefix, not for everything.** A default-free
 * router holds close to a million routes and a platform that asked for all of
 * them would be a platform that ran out of memory on the first call. The
 * question an operator has is always about a prefix — "where does this
 * customer's /24 go" — so that is the question the contract takes. A null
 * asks for what the device considers its own, which on an edge router is a
 * short list and on a transit router is where an adapter should refuse.
 *
 * `BgpSessionWrite` — shutting a peer — has no method here. It is in the same
 * position as a firewall policy write: behind the change workflow.
 */
interface RoutingProvider extends InfrastructureAdapter
{
    /**
     * @param  string  $target  A node key.
     * @param  string|null  $prefix  Narrow to the routes covering this prefix,
     *                               as text. Null asks for the device's own.
     * @return list<RouteEntry>
     */
    public function routes(string $target, ?string $prefix = null): array;

    /**
     * @param  string  $target  A node key.
     * @return list<BgpSession>
     */
    public function bgpSessions(string $target): array;
}
