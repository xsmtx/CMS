<?php

declare(strict_types=1);

namespace App\Application\Tax;

use App\Domain\Tax\TaxableSupply;
use App\Infrastructure\Tax\Models\TaxRule;
use App\Infrastructure\Tax\Models\TaxSetting;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;

/**
 * Which rules apply to a supply, and in which order.
 *
 * The matching is the part an operator has to be able to predict, because they
 * will write overlapping rows on purpose — a national rate and one province —
 * and the platform's answer has to be the one they expected (ADR 0045):
 *
 * 1. Everything that could apply is collected: the place, the date, what is
 *    being sold, and who is buying.
 * 2. Within a level, the **most specific** wins — a postcode beats a region,
 *    which beats a country, which beats the rule with no place at all.
 * 3. `priority` breaks a remaining tie, higher first, and the oldest row wins
 *    after that so the answer is stable rather than whatever the database
 *    returns.
 * 4. One rule per level. Two national VAT rates are a mistake in the
 *    configuration, and charging both would turn it into a mistake on an
 *    invoice.
 */
final readonly class TaxRules
{
    public function __construct(private OrganizationContext $organizations) {}

    /**
     * The rules that apply, at most one per level, lowest level first.
     *
     * @return list<TaxRule>
     */
    public function matching(string $sellerId, TaxableSupply $supply, ?CarbonImmutable $on = null): array
    {
        $date = $on ?? CarbonImmutable::now();

        $candidates = $this->rulesFor($sellerId);

        $eligible = array_values(array_filter(
            $candidates,
            fn (TaxRule $rule): bool => $this->applies($rule, $supply, $date),
        ));

        $byLevel = [];

        foreach ($eligible as $rule) {
            $current = $byLevel[$rule->level] ?? null;

            if ($current === null || $this->beats($rule, $current)) {
                $byLevel[$rule->level] = $rule;
            }
        }

        ksort($byLevel);

        return array_values($byLevel);
    }

    /**
     * The seller's answers to the questions that are not a rate.
     *
     * A seller with no row gets the defaults rather than nothing: an
     * installation that has never opened the screen still has to be able to
     * issue an invoice.
     */
    public function settings(string $sellerId): TaxSetting
    {
        $setting = $this->organizations->withoutBoundary(
            static fn (): ?TaxSetting => TaxSetting::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $sellerId)
                ->first(),
        );

        return $setting ?? new TaxSetting(['organization_id' => $sellerId]);
    }

    /**
     * @return list<TaxRule>
     */
    private function rulesFor(string $sellerId): array
    {
        /*
         * Read outside the boundary, filtered by the seller explicitly.
         *
         * The supply being taxed belongs to a customer, so the acting boundary
         * at checkout is the customer's own subtree — which does not contain the
         * seller's rules. Asking for them inside it would quietly find none and
         * charge no tax, which is the worst possible failure here: an invoice
         * that looks finished and is wrong.
         */
        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(
                TaxRule::query()
                    ->withoutGlobalScope('organization')
                    ->where('organization_id', $sellerId)
                    ->where('is_active', true)
                    ->orderBy('level')
                    ->orderByDesc('priority')
                    ->oldest()
                    ->get()
                    ->all()
            ),
        );
    }

    private function applies(TaxRule $rule, TaxableSupply $supply, CarbonImmutable $date): bool
    {
        if (! $rule->appliesOn($date)) {
            return false;
        }

        if (! $rule->applies_to->covers($supply->appliesTo)) {
            return false;
        }

        if (! $rule->customer_kind->covers($supply->isBusiness)) {
            return false;
        }

        if ($rule->country_code !== null
            && strtoupper($rule->country_code) !== strtoupper((string) $supply->countryCode)) {
            return false;
        }

        if ($rule->region_code !== null
            && strtoupper($rule->region_code) !== strtoupper((string) $supply->stateCode)) {
            return false;
        }

        return $rule->matchesPostcode($supply->postalCode);
    }

    private function beats(TaxRule $candidate, TaxRule $incumbent): bool
    {
        if ($candidate->specificity() !== $incumbent->specificity()) {
            return $candidate->specificity() > $incumbent->specificity();
        }

        if ($candidate->priority !== $incumbent->priority) {
            return $candidate->priority > $incumbent->priority;
        }

        /*
         * Oldest wins, so the answer does not change when two equally good rules
         * exist and the database hands them back in a different order.
         *
         * A row that has somehow no timestamp sorts last rather than first: the
         * one an operator wrote on purpose should not lose to one that arrived
         * without a date.
         */
        $far = new CarbonImmutable('9999-12-31');

        return ($candidate->created_at ?? $far)->lt($incumbent->created_at ?? $far);
    }
}
