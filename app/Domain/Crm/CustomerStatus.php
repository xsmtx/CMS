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
    /**
     * Their details are missing or wrong, and somebody has to fix them
     * before the account can do anything else.
     *
     * Deliberately a status rather than a flag beside one. It is mutually
     * exclusive with being active — an account cannot simultaneously be
     * trading and be blocked pending information — and a boolean beside a
     * status is two sources of truth for one question.
     */
    case InformationRequired = 'information_required';

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
     * Whether this account may reach anything in the portal beyond support.
     *
     * The one thing an account in `information_required` can still do is
     * open a ticket — because that is how they tell somebody what is wrong
     * with their details, and blocking it would leave them with no way to
     * become un-blocked.
     */
    public function isRestrictedToSupport(): bool
    {
        return $this === self::InformationRequired;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::InformationRequired, self::Closed],
            self::Active => [self::InformationRequired, self::Suspended, self::Closed],
            // Out of it in either direction: the details were fixed, or
            // nobody ever fixed them.
            self::InformationRequired => [self::Active, self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::InformationRequired, self::Closed],
            self::Closed => [self::Active],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
