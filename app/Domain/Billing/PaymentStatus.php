<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Where one attempt to pay got to.
 *
 * `pending` exists because most gateways answer asynchronously: the
 * customer has been sent somewhere, and what happens next arrives on a
 * webhook rather than in the response to our request.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function labelKey(): string
    {
        return 'billing.payment_statuses.'.$this->value;
    }

    /**
     * Whether this attempt actually moved money. Only a completed payment
     * counts toward an invoice.
     */
    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Completed, self::PartiallyRefunded => true,
            default => false,
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function isRefundable(): bool
    {
        return $this === self::Completed || $this === self::PartiallyRefunded;
    }
}
