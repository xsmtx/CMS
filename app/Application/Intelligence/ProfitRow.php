<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Application\Reports\MoneyByCurrency;

/**
 * One line of the profitability report (§21).
 *
 * **The margin is not always statable, and that is the point of this
 * class.** There is no rate anywhere in this product, so a service earning
 * euros on a server costing lira has a revenue, a cost, and no margin — and
 * the honest answer is to say so rather than to print the revenue as though
 * the cost were zero.
 *
 * The rule is narrow and checkable: a margin is stated when **every currency
 * that has a cost also has revenue**. A currency with revenue and no cost is
 * fine — it means nobody has recorded a cost against it, which is true and
 * is what the figure says.
 */
final readonly class ProfitRow
{
    public function __construct(
        public string $key,
        public string $label,
        public MoneyByCurrency $revenue,
        public MoneyByCurrency $cost,
        /** How many services are behind it, which is what makes a figure readable. */
        public int $services,
    ) {}

    /**
     * Whether a cost was recorded in a currency this row earns nothing in.
     *
     * The one case where no margin can honestly be given.
     */
    public function isMixedCurrency(): bool
    {
        foreach ($this->cost->currencies() as $currency) {
            if ($this->cost->minorFor($currency) === 0) {
                continue;
            }

            if (! in_array($currency, $this->revenue->currencies(), strict: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Revenue less cost, per currency, or null where it cannot be said.
     */
    public function margin(): ?MoneyByCurrency
    {
        if ($this->isMixedCurrency()) {
            return null;
        }

        $margin = new MoneyByCurrency;

        foreach ($this->revenue->currencies() as $currency) {
            $margin->add(
                $currency,
                $this->revenue->minorFor($currency) - $this->cost->minorFor($currency),
            );
        }

        return $margin;
    }
}
