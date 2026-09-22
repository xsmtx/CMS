<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

/**
 * Where an order is in its life.
 *
 * The states come from the product specification and are deliberately more
 * granular than "paid or not": a hold for payment review and a hold for
 * fraud review look identical to a customer but are entirely different
 * problems for the operator, and collapsing them would lose the distinction
 * exactly where it is needed.
 *
 * Transitions are explicit. An order that reached a terminal state does not
 * quietly move again.
 */
enum OrderStatus: string
{
    /** Being built. Not yet submitted by the customer. */
    case Draft = 'draft';

    /** Submitted and accepted, before any payment or risk decision. */
    case Pending = 'pending';

    /** Waiting for the customer to pay. */
    case AwaitingPayment = 'awaiting_payment';

    /** A payment arrived that the gateway or an operator wants looked at. */
    case PaymentReview = 'payment_review';

    /** The risk engine asked for a human. */
    case FraudReview = 'fraud_review';

    case Paid = 'paid';

    /** Provisioning has started. */
    case Provisioning = 'provisioning';

    /** Some lines are live and some are not. */
    case PartiallyFulfilled = 'partially_fulfilled';

    case Active = 'active';

    case Cancelled = 'cancelled';

    /** Provisioning or payment failed in a way that needs attention. */
    case Failed = 'failed';

    case Refunded = 'refunded';

    public function labelKey(): string
    {
        return 'ordering.statuses.'.$this->value;
    }

    /**
     * Whether the customer still owes money on this order.
     */
    public function awaitsPayment(): bool
    {
        return $this === self::AwaitingPayment || $this === self::PaymentReview;
    }

    /**
     * Whether a human has to act before anything else happens.
     */
    public function needsReview(): bool
    {
        return $this === self::FraudReview || $this === self::PaymentReview;
    }

    /**
     * Whether the order has been paid for, whatever has happened since.
     */
    public function isPaid(): bool
    {
        return match ($this) {
            self::Paid, self::Provisioning, self::PartiallyFulfilled, self::Active, self::Refunded => true,
            default => false,
        };
    }

    /**
     * Nothing follows a terminal state. Cancelled is terminal; failed is
     * not, because a failed provisioning run is retried.
     */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Refunded;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Pending, self::Cancelled],

            // A submitted order goes to a hold, to payment, or straight to
            // paid when it costs nothing.
            self::Pending => [
                self::AwaitingPayment,
                self::FraudReview,
                self::Paid,
                self::Cancelled,
                self::Failed,
            ],

            self::AwaitingPayment => [
                self::Paid,
                self::PaymentReview,
                self::FraudReview,
                self::Cancelled,
                self::Failed,
            ],

            // A review ends one of three ways: released, refused, or sent
            // back to await payment because the attempt was abandoned.
            self::PaymentReview => [self::Paid, self::AwaitingPayment, self::Cancelled, self::Failed],
            self::FraudReview => [self::AwaitingPayment, self::Paid, self::Cancelled],

            self::Paid => [self::Provisioning, self::Active, self::Refunded, self::Failed],

            self::Provisioning => [
                self::Active,
                self::PartiallyFulfilled,
                self::Failed,
            ],

            self::PartiallyFulfilled => [self::Active, self::Provisioning, self::Failed],

            // A live order can still be refunded; it cannot go back to
            // provisioning, because that is a service-level action.
            self::Active => [self::Refunded],

            // Retry after a failure resumes where it failed.
            self::Failed => [self::Provisioning, self::AwaitingPayment, self::Cancelled],

            self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * The statuses an operator may set by hand.
     *
     * Payment and provisioning move the order themselves; offering those as
     * buttons invites an operator to mark an order paid that nobody paid
     * for.
     *
     * @return list<self>
     */
    public function manualTransitions(): array
    {
        return array_values(array_filter(
            $this->allowedTransitions(),
            static fn (self $status): bool => in_array($status, [
                self::Cancelled,
                self::AwaitingPayment,
                self::Failed,
            ], strict: true),
        ));
    }
}
