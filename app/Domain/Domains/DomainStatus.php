<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * Where a domain is.
 *
 * Like a service, this describes something outside the database — but a
 * registry is stricter than a control panel. A domain does not get
 * suspended and unsuspended; it expires, sits in redemption at a penalty,
 * and is then gone. Those are the registry's rules and this enum follows
 * them rather than inventing a friendlier set.
 *
 * `failed` is a state here for the same reason it is one for a service: an
 * operator needs somewhere to find the registrations that did not happen.
 */
enum DomainStatus: string
{
    /** Bought, nothing sent to a registrar yet. */
    case Pending = 'pending';

    /** A job is talking to the registrar now. */
    case Registering = 'registering';

    /** Waiting for the customer to authorise, or for the losing registrar. */
    case TransferPending = 'transfer_pending';

    /** The transfer is running at the registry. */
    case Transferring = 'transferring';

    case Active = 'active';

    /** Past its date, still recoverable at the normal fee. */
    case Expired = 'expired';

    /** In the registry's redemption period. Recoverable at a penalty. */
    case Redemption = 'redemption';

    /** Never registered, or given up before it was. */
    case Cancelled = 'cancelled';

    /** Gone from the registry. */
    case Deleted = 'deleted';

    /** An operation failed and a human has to look. */
    case Failed = 'failed';

    public function labelKey(): string
    {
        return 'domains.statuses.'.$this->value;
    }

    /**
     * Whether the customer has a working domain.
     */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the registry holds this name for this account.
     *
     * Decides whether an operation means a remote call, and whether
     * registering again would be a duplicate.
     */
    public function existsAtRegistry(): bool
    {
        return match ($this) {
            self::Active, self::Expired, self::Redemption, self::Transferring => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Deleted || $this === self::Cancelled;
    }

    /**
     * Whether a registration run may start from here.
     *
     * `failed` is included: retrying after fixing whatever broke is exactly
     * what an operator does.
     */
    public function canRegister(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [
                self::Registering,
                self::TransferPending,
                self::Active,
                self::Failed,
                self::Cancelled,
            ],

            self::Registering => [self::Active, self::Failed],

            self::TransferPending => [self::Transferring, self::Failed, self::Cancelled],

            self::Transferring => [self::Active, self::Failed, self::Cancelled],

            // A registry does not un-expire a name on its own; a renewal
            // does, and that goes back through active.
            self::Active => [self::Expired, self::Cancelled, self::Deleted, self::Failed],

            self::Expired => [self::Active, self::Redemption, self::Deleted, self::Failed],

            self::Redemption => [self::Active, self::Deleted, self::Failed],

            self::Failed => [self::Registering, self::TransferPending, self::Active, self::Cancelled],

            self::Cancelled, self::Deleted => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /**
     * What an operator may declare by hand.
     *
     * `registering` and `transferring` are not offered: they are states a
     * job enters. `active` is, unlike for a service, because a manual
     * registrar exists precisely so an operator can say "I registered this
     * at the registrar's own panel".
     *
     * @return list<self>
     */
    public function manualTransitions(): array
    {
        return array_values(array_filter(
            $this->allowedTransitions(),
            static fn (self $status): bool => $status !== self::Registering
                && $status !== self::Transferring,
        ));
    }
}
