<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;

/**
 * What moving a service between plans costs today.
 *
 * **Two amounts, and their difference is their difference.** The credit for
 * the part of the current term nobody will use and the charge for the new plan
 * over that same part are computed separately and the total is their sum — so
 * the two lines an invoice shows add up to the figure that was charged, by
 * construction. Computing one net figure and then inventing two lines to
 * explain it is how a customer finds that the explanation and the total
 * disagree by a penny.
 *
 * **A cycle change restarts the term, and that changes what is charged.**
 * Monthly to annual is not twelve months of difference; it is a new year
 * beginning today, so the charge is the **whole** new price and the credit is
 * still only the unused remainder of the old month. `restartsTerm` says which
 * of the two arithmetics happened, because an operator reading the record
 * three months later cannot tell from the numbers alone.
 *
 * **A downgrade is a negative difference and never a refund.** Money that has
 * been taken is not sent back by a panel: the difference becomes account
 * credit through a credit note, which is what ADR 0023 and ADR 0024 already
 * decided for every other correction in this product.
 */
final readonly class ProratedChange
{
    public function __construct(
        /** The unused part of the term already paid for. */
        public Money $credit,
        /** The new plan over the same remainder, or a whole new term. */
        public Money $charge,
        public int $daysRemaining,
        public int $termDays,
        public BillingCycle $fromCycle,
        public BillingCycle $toCycle,
        /** True when the cycle changed and a new term begins today. */
        public bool $restartsTerm,
    ) {}

    /**
     * What the customer owes, which may be negative.
     *
     * Negative is a downgrade and is handled as a credit rather than clamped
     * to zero: a figure clamped here would silently keep money the customer
     * had already paid for a plan they no longer have.
     */
    public function difference(): Money
    {
        return $this->charge->minus($this->credit);
    }

    public function isUpgrade(): bool
    {
        return $this->difference()->isPositive();
    }

    /**
     * Nothing to invoice and nothing to credit.
     *
     * A real answer rather than an error: moving between two plans that cost
     * the same, or moving on the day a term ends, both land here — and both
     * should simply happen.
     */
    public function isFree(): bool
    {
        return $this->difference()->isZero();
    }
}
