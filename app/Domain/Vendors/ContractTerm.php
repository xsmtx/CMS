<?php

declare(strict_types=1);

namespace App\Domain\Vendors;

/**
 * How often a contract comes round (§24).
 *
 * Deliberately not `BillingCycle`. That enum is what a **customer** is
 * charged on and it reaches invoices, order lines and the price matrix; a
 * supplier's term is a different fact about a different relationship, and
 * sharing the type would mean a change made for one eventually being a change
 * to the other. They happen to have overlapping words, which is not the same
 * as being the same thing — `FindingSeverity` and `AlertSeverity` made the
 * same call for the same reason.
 *
 * `Once` is real and matters: a one-off hardware purchase has a vendor, a
 * price and a warranty, and no renewal date at all. A term list without it
 * would make somebody invent a yearly contract that does not exist.
 */
enum ContractTerm: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case Triennial = 'triennial';
    case Once = 'once';

    public function labelKey(): string
    {
        return 'vendors.terms.'.$this->value;
    }

    /**
     * How many months one period is, or null for a purchase that does not
     * repeat.
     *
     * Null rather than zero, because zero would divide into a monthly figure
     * and produce one — and a one-off payment spread across months is the
     * mistake `AllocateCosts` already refuses.
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
            self::Triennial => 36,
            self::Once => null,
        };
    }
}
