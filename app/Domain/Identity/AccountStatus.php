<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Whether an account may authenticate.
 *
 * Separate from soft deletion: a suspended account still exists, still owns
 * its history, and can be restored without resurrecting a deleted row.
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    public function labelKey(): string
    {
        return 'identity.statuses.'.$this->value;
    }

    /**
     * Reason returned to the caller when authentication is refused. It is
     * deliberately vague about which of the two states applies.
     */
    public function refusalKey(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::Suspended, self::Closed => 'identity.auth.account_unavailable',
        };
    }
}
