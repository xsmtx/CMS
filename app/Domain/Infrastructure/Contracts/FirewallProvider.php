<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Network\FirewallRule;
use App\Domain\Infrastructure\Network\SessionSummary;

/**
 * Something that can read a firewall's policy and how busy it is.
 *
 * Two reads, and the second is deliberately a **summary**. A mid-sized
 * firewall holds hundreds of thousands of sessions; pulling the table would
 * fall over on the night it was most wanted, and would put a record of who
 * spoke to whom into a hosting platform's database, which is the most
 * sensitive thing it could hold. The question an operator actually asks — is
 * this box near its session limit — is a number.
 *
 * `FirewallPolicyWrite` is in `Capability` and has no method here for the
 * reason `NetworkDeviceProvider` gives: it is the most consequential thing
 * this platform will ever do, and it arrives behind the guarded workflow with
 * a backup in front of it, not before.
 */
interface FirewallProvider extends InfrastructureAdapter
{
    /**
     * The policy, in the order the device evaluates it.
     *
     * Ordered by `FirewallRule::$position`, because first match wins and a
     * policy in the wrong order is a policy that reads correctly and means
     * something else.
     *
     * @param  string  $target  A node key.
     * @return list<FirewallRule>
     */
    public function policies(string $target): array;

    /**
     * @param  string  $target  A node key.
     */
    public function sessions(string $target): SessionSummary;
}
