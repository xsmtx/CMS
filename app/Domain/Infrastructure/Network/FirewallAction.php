<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * What a firewall rule does with a packet that matches it.
 *
 * `Deny` and `Reject` are separate because they are different on the wire and
 * different to diagnose: a denied packet vanishes and the client waits for a
 * timeout, a rejected one comes back refused and the client fails at once. An
 * operator reading a policy needs to know which, because "the connection hangs"
 * and "the connection is refused" are the two symptoms they will be handed.
 */
enum FirewallAction: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Reject = 'reject';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'infrastructure.firewall_actions.'.$this->value;
    }
}
