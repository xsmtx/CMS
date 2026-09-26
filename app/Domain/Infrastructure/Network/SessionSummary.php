<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Network;

/**
 * How busy a firewall is, as numbers rather than as a session table.
 *
 * `FirewallSessionRead` is deliberately a **summary** capability. A mid-sized
 * firewall holds hundreds of thousands of sessions and a platform that pulled
 * the table would be a platform that fell over on the night it was most
 * wanted - and would be storing, for a few minutes, a record of who spoke to
 * whom, which is the most sensitive thing a hosting platform could hold.
 *
 * The one operational question a summary answers and a table does not answer
 * any better: is this box near its session limit. `capacity` is the device's
 * own maximum where it reports one, so a screen can say 84% rather than a
 * number nobody can scale.
 */
final readonly class SessionSummary
{
    /**
     * @param  array<string, int>  $byProtocol  Keyed by the protocol as the
     *                                          device names it: `tcp`, `udp`,
     *                                          `icmp`.
     */
    public function __construct(
        public string $target,
        public int $count,
        public ?int $capacity = null,
        public array $byProtocol = [],
    ) {}

    /**
     * How full, as a ratio, or null when the device did not say what full is.
     *
     * Null rather than a guessed ceiling: `CapacityForecast` already refuses to
     * answer where nothing reported a maximum, for the same reason.
     */
    public function utilisation(): ?float
    {
        if ($this->capacity === null || $this->capacity <= 0) {
            return null;
        }

        return $this->count / $this->capacity;
    }
}
