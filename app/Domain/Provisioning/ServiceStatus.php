<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * Where a service is.
 *
 * Unlike an order or an invoice, this enum describes something outside the
 * database: an account on somebody else's control panel. Two consequences
 * shape it.
 *
 * `failed` is a **state**, not an exception. A provisioning run that cannot
 * succeed leaves the service somewhere an operator can find it, with the
 * reason recorded, rather than throwing into a log nobody reads or leaving
 * it in `provisioning` forever.
 *
 * `terminated` is terminal in the strongest sense available here: the
 * account is gone at the provider, and no transition brings it back. A new
 * service is a new account.
 */
enum ServiceStatus: string
{
    /** Bought and waiting. Nothing exists remotely yet. */
    case Pending = 'pending';

    /** A job is talking to a provider right now. */
    case Provisioning = 'provisioning';

    case Active = 'active';

    /** Turned off at the provider, and recoverable. */
    case Suspended = 'suspended';

    /**
     * Past due but still running. Phase 9's dunning drives entry; the state
     * exists now so that it has somewhere to go.
     */
    case GracePeriod = 'grace_period';

    /** The customer asked to stop at the end of the term. */
    case CancelPending = 'cancel_pending';

    /** Gone at the provider. */
    case Terminated = 'terminated';

    /** An operation failed and a human has to look. */
    case Failed = 'failed';

    public function labelKey(): string
    {
        return 'provisioning.statuses.'.$this->value;
    }

    /**
     * Whether the customer is getting what they paid for.
     */
    public function isUsable(): bool
    {
        return in_array($this, [self::Active, self::GracePeriod, self::CancelPending], true);
    }

    /**
     * Whether an account exists at the provider.
     *
     * Decides whether terminating means a remote call or just a row
     * change, and whether a second create would be a duplicate.
     */
    public function existsRemotely(): bool
    {
        return match ($this) {
            self::Active, self::Suspended, self::GracePeriod, self::CancelPending => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Terminated;
    }

    /**
     * Whether a provisioning run may start from here.
     *
     * `failed` is included on purpose: retrying is exactly what an operator
     * does after fixing whatever broke.
     */
    public function canProvision(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Provisioning, self::Active, self::Failed, self::Terminated],

            // A run ends three ways: it worked, it did not, or the account
            // was already there.
            self::Provisioning => [self::Active, self::Failed, self::Suspended],

            self::Active => [
                self::Suspended,
                self::GracePeriod,
                self::CancelPending,
                self::Terminated,
                self::Failed,
            ],

            self::Suspended => [self::Active, self::Terminated, self::Failed],

            self::GracePeriod => [self::Active, self::Suspended, self::Terminated, self::Failed],

            self::CancelPending => [self::Active, self::Terminated],

            // Retrying a failed run goes back through provisioning;
            // giving up on one ends in termination.
            self::Failed => [self::Provisioning, self::Active, self::Suspended, self::Terminated],

            self::Terminated => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /**
     * What an operator may choose by hand.
     *
     * `provisioning` is not offered: it is a state a job enters, not one a
     * human declares. Nor is `active`, because saying a service works does
     * not make an account exist — that is what running the operation is
     * for.
     *
     * @return list<self>
     */
    public function manualTransitions(): array
    {
        return array_values(array_filter(
            $this->allowedTransitions(),
            static fn (self $status): bool => $status !== self::Provisioning && $status !== self::Active,
        ));
    }
}
