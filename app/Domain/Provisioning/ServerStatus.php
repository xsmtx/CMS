<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * Whether a node may take work.
 *
 * `full` is deliberately separate from `maintenance`: one is a fact the
 * platform worked out from capacity, the other is a decision an operator
 * made. Collapsing them would mean an operator's flag being cleared by a
 * customer terminating an account.
 */
enum ServerStatus: string
{
    case Active = 'active';

    /** An operator took it out of rotation. */
    case Maintenance = 'maintenance';

    /** At capacity. Set by the platform, not by a person. */
    case Full = 'full';

    case Offline = 'offline';

    public function labelKey(): string
    {
        return 'provisioning.server_statuses.'.$this->value;
    }

    public function acceptsPlacement(): bool
    {
        return $this === self::Active;
    }
}
