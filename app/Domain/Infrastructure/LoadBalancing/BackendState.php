<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\LoadBalancing;

/**
 * What a load balancer says about one backend (§9).
 *
 * **`Draining` is the member that earns the enum.** A backend that is
 * draining is still serving the connections it already has and is taking no
 * new ones; it is neither up nor down, and a scale that made it one of those
 * would make a planned maintenance look like an outage or make an outage look
 * planned. It is also the state this platform can *put* a backend into, which
 * is the whole of §16's rolling maintenance.
 *
 * `Disabled` is somebody's decision and `Down` is a health check's verdict.
 * A rack of failures and an afternoon somebody took four backends out must
 * not look alike — the same distinction `PortState` draws.
 */
enum BackendState: string
{
    case Up = 'up';
    case Draining = 'draining';
    case Down = 'down';
    case Disabled = 'disabled';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'infrastructure.loadbalancing.states.'.$this->value;
    }

    /**
     * A word `status.ts` knows, pinned for every enum by `VocabularyTest`.
     *
     * Draining is `maintenance` rather than `warning`: it is a thing somebody
     * chose, and the maintenance tone is what this product already uses for
     * "taken out of service on purpose".
     */
    public function tone(): string
    {
        return match ($this) {
            self::Up => 'healthy',
            self::Draining => 'maintenance',
            self::Down => 'critical',
            self::Disabled => 'neutral',
            self::Unknown => 'unknown',
        };
    }

    /** Whether a drain would change anything. */
    public function isServing(): bool
    {
        return $this === self::Up;
    }
}
