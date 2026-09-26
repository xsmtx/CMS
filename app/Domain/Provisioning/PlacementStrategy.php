<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * How a server group picks a node.
 *
 * A property of the group rather than of a product, so an operator changes
 * placement for everything at once without editing a catalog entry.
 *
 * `Manual` is the absence of a strategy rather than an implementation of
 * one: it means an operator names the server, and a service created without
 * one waits rather than landing somewhere arbitrary.
 */
enum PlacementStrategy: string
{
    /** The node holding the fewest services. The dull, correct default. */
    case LeastAccounts = 'least_accounts';

    /** Proportional to an operator's weight, for mixed hardware. */
    case Weighted = 'weighted';

    /** The most headroom left, as a share of capacity. */
    case CapacityAware = 'capacity_aware';

    /** Prefers the customer's region, then falls back to least accounts. */
    case RegionAware = 'region_aware';

    /**
     * Every factor the installation can measure, weighted, with the reason
     * written down.
     *
     * A member of its own rather than a change to `CapacityAware`: that one
     * counts accounts against a limit an operator typed, answers the same way
     * on an installation nothing monitors, and is somebody's production
     * setting. A strategy that started reading a monitoring system because a
     * release shipped would be a placement decision changing under an operator
     * who did not ask for it.
     */
    case Scored = 'scored';

    case Manual = 'manual';

    public function labelKey(): string
    {
        return 'provisioning.strategies.'.$this->value;
    }

    public function isAutomatic(): bool
    {
        return $this !== self::Manual;
    }
}
