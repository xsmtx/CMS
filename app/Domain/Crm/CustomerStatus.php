<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * Commercial state of a customer relationship.
 *
 * Distinct from the account status of the people who sign in: a closed
 * customer still has contacts whose accounts exist and whose history has to
 * stay readable.
 */
enum CustomerStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function labelKey(): string
    {
        return 'crm.statuses.'.$this->value;
    }

    /**
     * Whether new orders may be placed. Suspension stops new business while
     * leaving existing services alone; termination is a separate decision
     * made per service.
     */
    public function canTransact(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Closed],
            self::Active => [self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::Closed],
            self::Closed => [self::Active],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
