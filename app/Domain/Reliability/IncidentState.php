<?php

declare(strict_types=1);

namespace App\Domain\Reliability;

/**
 * Where an incident has got to, in the four words the industry already uses.
 *
 * Deliberately statuspage.io's vocabulary rather than a better one of our
 * own. An operator who has run anything knows what "identified" means and
 * what it implies about the next update; a customer reading a status page has
 * seen these words on every other status page they have ever looked at. A
 * platform that invented `triaging` and `mitigating` would be asking both of
 * them to learn a private language during an outage.
 *
 * The three open states are a promise about the **next message**, which is
 * why they are worth distinguishing:
 *
 * - `Investigating` — we know something is wrong and not yet why.
 * - `Identified` — we know why, and we are doing something about it.
 * - `Monitoring` — we have done it and we are watching before saying so.
 *
 * `Resolved` is the end, and it is the moment the impact figure is frozen:
 * the graph moves, and an impact recomputed in March is not the impact
 * anybody acted on.
 */
enum IncidentState: string
{
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }

    /**
     * The tone `AppStatus` draws it in.
     *
     * Decided here rather than in a Vue file, for the reason
     * `AlertSeverity::tone()` is: one mapping, on the server, so the browser
     * needs the value and nothing else.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Investigating => 'critical',
            self::Identified => 'warning',
            self::Monitoring => 'info',
            self::Resolved => 'healthy',
        };
    }

    public function labelKey(): string
    {
        return 'reliability.incident_states.'.$this->value;
    }
}
