<?php

declare(strict_types=1);

namespace App\Domain\Resellers;

/**
 * What a row on a reseller's account records.
 *
 * The same shape the customer ledger has (ADR 0024): amounts are always
 * positive and the kind decides direction, so a payment recorded as a
 * negative number is a ledger that lies twice.
 *
 * Deliberately smaller than `TransactionKind`. A reseller has no invoices
 * of its own here yet — the provider billing a reseller is a billing model
 * this phase does not guess at — so there is nothing for `credit_applied`
 * to be applied to.
 */
enum ResellerLedgerKind: string
{
    /** The reseller paid the provider. */
    case Payment = 'payment';

    /** A commission the provider granted, or a top-up. */
    case Credit = 'credit';

    /** What the reseller owes for something one of their customers bought. */
    case Charge = 'charge';

    /** The provider corrected the balance, with a reason. */
    case Adjustment = 'adjustment';

    public function labelKey(): string
    {
        return 'organizations.ledger_kinds.'.$this->value;
    }

    /**
     * Whether the amount increases what the reseller holds.
     *
     * Signing lives here rather than at each call site, for the reason it
     * does in billing: a charge recorded as an increase is a balance nobody
     * can explain.
     */
    public function increasesBalance(): bool
    {
        return match ($this) {
            self::Payment, self::Credit => true,
            self::Charge, self::Adjustment => false,
        };
    }
}
