<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * Where a BGP session is in the state machine, in RFC 4271's own words.
 *
 * The vocabulary is the protocol's rather than a simplification of it, because
 * the whole point of looking is to tell the three failures apart. `Idle` is a
 * session that is not being attempted - usually an administrative shutdown or
 * a peer that has been damped. `Connect` and `Active` are a TCP session that
 * will not come up, which is a firewall or a cable. `OpenSent` and
 * `OpenConfirm` mean TCP is fine and the peers disagree about who they are,
 * which is an ASN or an authentication key. Collapsing those to "down" would
 * throw away which of three teams to call.
 */
enum BgpState: string
{
    case Idle = 'idle';
    case Connect = 'connect';
    case Active = 'active';
    case OpenSent = 'open_sent';
    case OpenConfirm = 'open_confirm';
    case Established = 'established';
    case Unknown = 'unknown';

    /** The only state in which the session is carrying routes. */
    public function isUp(): bool
    {
        return $this === self::Established;
    }

    public function labelKey(): string
    {
        return 'infrastructure.bgp_states.'.$this->value;
    }
}
