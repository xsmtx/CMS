<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Where an invoice is.
 *
 * `draft` is the only editable state. Everything after it is a record: an
 * invoice is a tax document in most of the world, and a document whose
 * numbers move is not evidence of anything. Corrections happen through a
 * credit note, not an edit.
 */
enum InvoiceStatus: string
{
    /** Being prepared. Not a document yet. */
    case Draft = 'draft';

    /** Issued and owed. */
    case Unpaid = 'unpaid';

    /** Some money arrived, not all of it. Normal, not an error. */
    case PartiallyPaid = 'partially_paid';

    /** Issued, owed, and past its due date. */
    case Overdue = 'overdue';

    case Paid = 'paid';

    case Cancelled = 'cancelled';

    case Refunded = 'refunded';

    public function labelKey(): string
    {
        return 'billing.statuses.'.$this->value;
    }

    /**
     * Whether the document exists as far as the customer and the tax
     * authority are concerned.
     */
    public function isIssued(): bool
    {
        return $this !== self::Draft;
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether money is still owed on it.
     */
    public function isOwed(): bool
    {
        return match ($this) {
            self::Unpaid, self::PartiallyPaid, self::Overdue => true,
            default => false,
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Paid || $this === self::Refunded;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Unpaid, self::Cancelled],

            self::Unpaid => [self::PartiallyPaid, self::Paid, self::Overdue, self::Cancelled],

            self::PartiallyPaid => [self::Paid, self::Overdue, self::Cancelled],

            // Overdue is a state rather than a computed flag, so dunning in
            // Phase 9 has something to transition from — and something to
            // transition back to when the customer pays.
            self::Overdue => [self::PartiallyPaid, self::Paid, self::Cancelled, self::Unpaid],

            self::Paid => [self::Refunded],

            // Terminal. A cancelled invoice is cancelled; a refunded one is
            // corrected by a credit note, not by moving again.
            self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
