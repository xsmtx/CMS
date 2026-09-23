<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * Where a service addon is.
 *
 * Deliberately smaller than `ServiceStatus`. An addon is not provisioned on
 * its own in this platform — the parent service's module puts the whole
 * account together, extra disk and all — so `provisioning` and `failed`
 * would be states nothing can ever set. An enum with unreachable members is
 * a screen with filters that always return nothing.
 *
 * What an addon does have is a life of its own commercially: it renews on
 * its own cycle, it can be dropped without touching the service it hangs
 * off, and it follows that service into suspension and out again.
 */
enum AddonStatus: string
{
    /** Bought, and waiting for the service it belongs to. */
    case Pending = 'pending';

    case Active = 'active';

    /** Off because the service it belongs to is off. */
    case Suspended = 'suspended';

    /** The customer asked to drop it at the end of the term. */
    case CancelPending = 'cancel_pending';

    /** Gone. Either dropped, or taken with the service it belonged to. */
    case Terminated = 'terminated';

    public function labelKey(): string
    {
        return 'provisioning.addon_statuses.'.$this->value;
    }

    /**
     * Whether the customer is getting what they paid for.
     */
    public function isUsable(): bool
    {
        return $this === self::Active || $this === self::CancelPending;
    }

    public function isTerminal(): bool
    {
        return $this === self::Terminated;
    }

    /**
     * Whether the renewal sweep should raise an invoice for this.
     *
     * A cancelled addon is still running until the term ends, and
     * invoicing it would charge for a term the customer has said they do
     * not want.
     */
    public function isBillable(): bool
    {
        return $this === self::Active || $this === self::Suspended;
    }

    /**
     * What this addon becomes when its service becomes `$status`.
     *
     * Returns null when nothing should happen: a terminated addon stays
     * terminated however often its service is resumed, because "the
     * customer dropped this" is not undone by "we turned the account back
     * on". The provisioning-only states have no addon equivalent, so they
     * leave it where it is.
     */
    public function follows(ServiceStatus $status): ?self
    {
        $next = match ($status) {
            ServiceStatus::Active => self::Active,
            ServiceStatus::Suspended, ServiceStatus::GracePeriod => self::Suspended,
            ServiceStatus::Terminated => self::Terminated,
            default => null,
        };

        if ($next === null || $next === $this || $this->isTerminal()) {
            return null;
        }

        // A cancellation the customer asked for survives everything short
        // of the end. Suspending the service and resuming it must not
        // quietly turn "they asked to stop paying for this" back into an
        // addon that renews — and moving it to `suspended` on the way
        // would lose the cancellation just as thoroughly as moving it
        // back to `active` on the way out.
        if ($this === self::CancelPending && $next !== self::Terminated) {
            return null;
        }

        return $next;
    }

    /**
     * @return list<self>
     */
    public static function billable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status->isBillable(),
        ));
    }
}
