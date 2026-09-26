<?php

declare(strict_types=1);

namespace App\Domain\Network;

/**
 * What an address is doing.
 *
 * Four states, and the two that are not obvious are the reason there are four.
 *
 * `Reserved` is an operator's decision — a gateway, a router's own address, a
 * block somebody is holding for next month. It is not available and it is not
 * assigned to anything, and collapsing it into either loses the one fact worth
 * keeping: that a person decided this.
 *
 * `Quarantined` is an address that has been released and should not go back out
 * yet. An address handed to a new customer the day after a spammer left it
 * arrives already on a blocklist, and the new customer's mail silently stops
 * working — which is a support ticket nobody can diagnose from inside this
 * platform.
 */
enum AddressState: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Assigned = 'assigned';
    case Quarantined = 'quarantined';

    public function labelKey(): string
    {
        return 'network.address_states.'.$this->value;
    }

    /** Whether this address may be handed out now. */
    public function isAllocatable(): bool
    {
        return $this === self::Available;
    }
}
