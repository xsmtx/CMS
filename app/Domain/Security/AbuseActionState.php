<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * How a decided action ended.
 *
 * `Manual` is not a failure and is the point of the enum: an action this
 * platform has no seam for is still a decision somebody made, and a desk
 * needs to see the list of things it has agreed to do and not yet done.
 */
enum AbuseActionState: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Manual = 'manual';
    case Failed = 'failed';

    public function tone(): string
    {
        return match ($this) {
            self::Done => 'healthy',
            self::Pending => 'info',
            self::Manual => 'warning',
            self::Failed => 'critical',
        };
    }

    public function labelKey(): string
    {
        return 'security.abuse.action_states.'.$this->value;
    }
}
