<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Network\DdosAttack;
use Carbon\CarbonImmutable;

/**
 * Something that knows an attack happened.
 *
 * A scrubbing service, a transit provider's portal, an appliance in the rack.
 * Core keeps the **event** and the attribution that makes it mean something;
 * the flow series stays where §7 says it belongs, which is anywhere but here.
 *
 * **It answers a window, not a page.** The sweep asks "what has happened
 * since" and an adapter returns what it has — so a platform that was down for
 * an afternoon catches up on the next run rather than losing an afternoon of
 * attacks permanently (ADR 0031's rule, arriving through somebody else's
 * API).
 *
 * **An attack that is still running is reported again each time**, with a
 * later end and usually a higher peak, and that is normal rather than
 * duplicate. `DdosAttack::$reference` is the provider's own identifier and
 * the only thing core deduplicates on.
 *
 * `DdosMitigationWrite` exists in `Capability` and has no method here, for
 * the reason the firewall's write did: asking somebody else's scrubbing
 * service to start or stop diverting a customer's traffic is a change with
 * consequences, and it belongs behind a workflow rather than behind a method
 * anything could call.
 */
interface DdosProvider extends InfrastructureAdapter
{
    /**
     * Attacks this source has seen since a moment.
     *
     * @return list<DdosAttack>
     */
    public function attacks(CarbonImmutable $since): array;
}
