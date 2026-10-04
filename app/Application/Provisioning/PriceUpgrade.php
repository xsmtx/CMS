<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Provisioning\Exceptions\UpgradeRefused;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ProratedChange;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Provisioning\Models\Service;
use Carbon\CarbonImmutable;

/**
 * What moving a service to another plan costs today.
 *
 * **Pure.** A service, a target, a date in, an answer out — no database write,
 * no adapter, no side effect. That is what lets the same arithmetic price the
 * preview a customer is shown and the invoice they are charged, which is the
 * rule the tax screen's Try-it panel states from the other side: a preview
 * that agreed with a second implementation and disagreed with the invoice
 * would be worse than none.
 *
 * **Two amounts, computed separately, whose difference is the total.** The
 * credit for the unused remainder and the charge for the new plan over that
 * same remainder are each one integer division, and the invoice shows both —
 * so the lines add up to the figure charged by construction rather than by
 * arithmetic nobody checked.
 *
 * **Days, not months.** A term is measured from the dates the service
 * actually has, so a month is 28, 30 or 31 days depending on which one it is.
 * Prorating by thirtieths is the approximation that makes February's customers
 * pay for a day that does not exist.
 *
 * **A cycle change restarts the term.** Monthly to annual is a new year
 * beginning today, so the charge is the whole new price while the credit is
 * still only the unused remainder of the old month. Prorating the new annual
 * price over twenty-three days would hand somebody a year of hosting for two
 * pounds.
 */
final readonly class PriceUpgrade
{
    public function handle(
        Service $service,
        Product $target,
        BillingCycle $cycle,
        ?CarbonImmutable $now = null,
    ): ProratedChange {
        $now = ($now ?? CarbonImmutable::now())->startOfDay();

        $from = $service->billing_cycle;

        if ($from === null || ! $from->isRecurring()) {
            // A one-time service has no term to prorate out of, and no
            // renewal to move. Refusing is honest where inventing a term
            // would be this platform deciding what somebody bought.
            throw UpgradeRefused::notRecurring($service->name);
        }

        if (! $cycle->isRecurring()) {
            throw UpgradeRefused::notRecurring($target->name);
        }

        $price = $this->priceFor($target, $cycle, $service->currency_code);

        $dueOn = $service->next_due_on?->startOfDay();

        if ($dueOn === null) {
            throw UpgradeRefused::noTerm($service->name);
        }

        /*
         * The term this service is actually in, from the dates it has rather
         * than from the cycle's nominal length. A service whose renewal was
         * moved by hand is still in a real term, and the one it is in is the
         * one being credited.
         */
        $termStart = $from->nextDueDate($dueOn) === null
            ? $dueOn
            : $this->termStart($dueOn, $from);

        $termDays = max(1, (int) $termStart->diffInDays($dueOn));

        // Never negative and never more than the term: a service past its due
        // date is being renewed rather than prorated, and a clamp here is
        // cheaper than a credit for days that have already been used.
        $daysRemaining = max(0, min($termDays, (int) $now->diffInDays($dueOn, false)));

        $restarts = $cycle !== $from;

        return new ProratedChange(
            credit: $this->prorate($service->recurring, $daysRemaining, $termDays),
            charge: $restarts
                ? $price->recurring
                : $this->prorate($price->recurring, $daysRemaining, $termDays),
            daysRemaining: $daysRemaining,
            termDays: $termDays,
            fromCycle: $from,
            toCycle: $cycle,
            restartsTerm: $restarts,
        );
    }

    /**
     * The price of the target, in the currency the service is already in.
     *
     * **Never converted.** There is no rate anywhere in this product, so a
     * plan that is not sold in this customer's currency cannot be moved to —
     * and saying so is the only honest answer. A price exists for a cycle and
     * a currency only when a row exists for it (ADR 0021).
     */
    private function priceFor(Product $target, BillingCycle $cycle, string $currency): ProductPrice
    {
        $price = $target->prices
            ->first(static fn (ProductPrice $row): bool => $row->billing_cycle === $cycle
                && $row->currency_code === $currency);

        if (! $price instanceof ProductPrice) {
            throw UpgradeRefused::notSold($target->name, $currency);
        }

        return $price;
    }

    /**
     * Where the term the service is in began.
     *
     * Counted back from the due date by the cycle's own month arithmetic,
     * which clamps rather than overflows — so a service renewing on the 31st
     * has a term that began on the 31st, or on the 28th in February.
     */
    private function termStart(CarbonImmutable $dueOn, BillingCycle $cycle): CarbonImmutable
    {
        return $dueOn->subMonthsNoOverflow($cycle->months());
    }

    /**
     * An amount over part of a term, by integer division.
     *
     * The same division for both halves, so neither the seller nor the
     * customer is systematically favoured by the rounding. Multiplying first
     * keeps the precision: dividing first and then multiplying would lose a
     * penny per day on anything under a pound.
     */
    private function prorate(Money $amount, int $days, int $termDays): Money
    {
        if ($days <= 0 || $termDays <= 0) {
            return Money::zero($amount->currency);
        }

        return Money::ofMinor(
            intdiv($amount->minorUnits * $days, $termDays),
            $amount->currency,
        );
    }
}
