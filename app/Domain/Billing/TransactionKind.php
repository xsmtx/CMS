<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What a ledger row records.
 *
 * The ledger is the truth; an invoice's paid amount is a cached total of
 * these rows and can be rebuilt from them at any time. Every kind here
 * either brings money in or takes it out, and none of them is ever edited.
 */
enum TransactionKind: string
{
    /** Money arrived from a customer. */
    case Payment = 'payment';

    /** Money went back to a customer. */
    case Refund = 'refund';

    /** Credit was put on the account: an overpayment, or a goodwill grant. */
    case CreditAdded = 'credit_added';

    /** Account credit was spent on an invoice. */
    case CreditApplied = 'credit_applied';

    /** A credit note reduced what was owed. */
    case CreditNote = 'credit_note';

    /** An operator corrected the balance, with a reason. */
    case Adjustment = 'adjustment';

    public function labelKey(): string
    {
        return 'billing.transaction_kinds.'.$this->value;
    }

    /**
     * Whether the amount increases what the customer has paid toward an
     * invoice. Signing lives here rather than at each call site, because a
     * refund recorded as a positive number is a ledger that lies.
     */
    public function increasesPaid(): bool
    {
        return match ($this) {
            self::Payment, self::CreditApplied, self::CreditNote => true,
            self::Refund, self::Adjustment, self::CreditAdded => false,
        };
    }

    /**
     * Whether the row moves the customer's account credit balance.
     */
    public function touchesCredit(): bool
    {
        return match ($this) {
            self::CreditAdded, self::CreditApplied => true,
            default => false,
        };
    }
}
