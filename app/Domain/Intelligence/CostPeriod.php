<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * How often a cost is paid (§21).
 *
 * Everything is reduced to a month, because a month is what a margin is
 * quoted in and what MRR is already measured in — and the reduction is
 * **integer division**, like every other arithmetic on money here.
 *
 * `OneOff` is the member that earns its place. A migration paid for once in
 * March is not a twelfth of anything: spreading it would make eleven months
 * look worse than they were and March look better. It lands in its own month
 * and nowhere else, which is the same rule `MonthlyRecurring` follows when it
 * skips a one-time line rather than counting it as zero.
 */
enum CostPeriod: string
{
    case Monthly = 'monthly';

    case Yearly = 'yearly';

    case OneOff = 'one_off';

    public function labelKey(): string
    {
        return 'intelligence.costs.periods.'.$this->value;
    }

    /**
     * What this costs in one month, in minor units.
     *
     * A year divided by twelve loses up to eleven minor units a year, which
     * is the cheaper mistake: the alternative is carrying a remainder across
     * months, and a margin that changes in December because of rounding is a
     * margin nobody can check.
     */
    public function monthlyMinor(int $minor): int
    {
        return match ($this) {
            self::Monthly => $minor,
            self::Yearly => intdiv($minor, 12),
            // Charged in full in its own month and in no other.
            self::OneOff => $minor,
        };
    }
}
