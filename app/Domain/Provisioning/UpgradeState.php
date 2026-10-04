<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * Where a request to move a service between plans has got to.
 *
 * **The money moves before the account does.** `awaiting_payment` is the state
 * that makes that true: an upgrade somebody asked for and has not paid for is
 * a request, and applying it at that point would be giving away the difference
 * — which is the same reason `AdvanceRenewalDates` listens for payment rather
 * than for an invoice being raised.
 *
 * A downgrade that costs nothing goes straight to `authorized`, because there
 * is nothing to wait for. A credit is written and the account moves.
 *
 * `failed` is a real end state, not an upgrade with a note (ADR 0026): the
 * customer has paid and the provider refused, and that is somebody's afternoon
 * rather than a row to retry silently.
 */
enum UpgradeState: string
{
    /** Written down, invoice raised, nothing has happened at the provider. */
    case AwaitingPayment = 'awaiting_payment';

    /** Paid, or free. The next sweep or listener applies it. */
    case Authorized = 'authorized';

    case Applying = 'applying';

    case Completed = 'completed';

    /** The provider refused. The money has moved and the plan has not. */
    case Failed = 'failed';

    /** Withdrawn before it was paid. */
    case Cancelled = 'cancelled';

    public function labelKey(): string
    {
        return 'provisioning.upgrades.states.'.$this->value;
    }

    /**
     * A word `status.ts` knows, so a screen tones it without a map of its own.
     */
    public function tone(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'warning',
            self::Authorized, self::Applying => 'info',
            self::Completed => 'healthy',
            self::Failed => 'critical',
            self::Cancelled => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::AwaitingPayment, self::Authorized, self::Applying => true,
            self::Completed, self::Failed, self::Cancelled => false,
        };
    }

    /**
     * Whether it may still be withdrawn.
     *
     * Only before anything has been attempted at the provider. A method rather
     * than a comparison at each call site, because the set is what changes.
     */
    public function isWithdrawable(): bool
    {
        return $this === self::AwaitingPayment || $this === self::Authorized;
    }
}
