<?php

declare(strict_types=1);

namespace App\Infrastructure\Tax;

use App\Application\Shared\ResolveSeller;
use App\Application\Tax\TaxRules;
use App\Domain\Shared\Money;
use App\Domain\Tax\Contracts\TaxCalculator;
use App\Domain\Tax\TaxableSupply;
use App\Domain\Tax\TaxComponent;
use App\Domain\Tax\TaxResult;
use App\Infrastructure\Tax\Models\TaxRule;
use App\Support\Organizations\OrganizationContext;

/**
 * The rules an operator wrote, applied.
 *
 * It implements the contract ADR 0022 put here and leaves that seam exactly as it
 * was: a module can still bind Avalara or TaxJar over the top and nothing in
 * billing changes. What is different is that the ordinary case — "charge 19% in
 * Germany and 20% in the UK" — is now rows on a screen rather than a package
 * somebody has to write (ADR 0045).
 *
 * It knows nothing about any country. It reads rows, checks a flag, multiplies
 * integers and names what it charged.
 *
 * Three behaviours worth reading before changing anything:
 *
 * **No rules means no tax**, and that is the right default rather than a failure.
 * An installation that has not opened the screen yet sells without tax, exactly
 * as it did before this existed; nothing has to be configured before the first
 * order can be placed.
 *
 * **An exemption charges nothing and says why.** A rule marked as exempting
 * validated businesses stops at a business elsewhere that gave a tax id, and the
 * result carries the reason so the invoice can print it. Whether that id is
 * really registered is not answered here: validating an EU number means calling
 * VIES, and core makes no such call.
 *
 * **A second level may compound.** Quebec charges its provincial tax on the
 * federal-tax-inclusive amount and the rest of Canada does not, so the difference
 * is one checkbox on the second rule rather than a special case in code.
 *
 * **A price may already include the tax.** Consumer prices do in most of Europe
 * and in Turkey, business-to-business prices almost never do, and the difference
 * is what a price *means* rather than what is added to it. When the seller says
 * their catalog is inclusive, the amount handed in is the gross: the net is
 * worked out by dividing it back down, the ordinary arithmetic then runs on that
 * net, and the result says `included` so that nothing downstream adds it on
 * again. Getting this wrong in either direction is a silent mispricing of every
 * order, which is why it is one flag read in one place.
 *
 * What it deliberately does **not** do is round a document. Per line or per
 * invoice is `TaxSetting`'s answer and belongs to whatever assembles the
 * document; a calculator handed one supply at a time that also rounded would
 * round twice.
 */
final readonly class ConfigurableTaxCalculator implements TaxCalculator
{
    public function __construct(
        private TaxRules $rules,
        private ResolveSeller $sellers,
        private OrganizationContext $organizations,
    ) {}

    public function calculate(TaxableSupply $supply): TaxResult
    {
        $zero = $supply->amount->multipliedBy(0);

        if ($supply->amount->isZero() || $supply->amount->isNegative()) {
            return TaxResult::none($zero);
        }

        $sellerId = $this->sellerId();

        if ($sellerId === null) {
            return TaxResult::none($zero);
        }

        $matched = $this->rules->matching($sellerId, $supply);

        if ($matched === []) {
            return TaxResult::none($zero);
        }

        $exemption = $this->exemptionFor($matched, $supply);

        if ($exemption !== null) {
            return TaxResult::none($zero, $exemption);
        }

        $inclusive = $this->rules->settings($sellerId)->prices_include_tax;

        // The amount handed in is the gross when the catalog is inclusive, so
        // the base the rules are charged on is what is left after the tax
        // inside it is taken back out.
        $base = $inclusive ? $this->netOf($supply->amount, $matched) : $supply->amount;

        $running = $zero;
        $components = [];

        foreach ($matched as $rule) {
            // A compound rule is charged on the base plus what the levels below
            // it have already added. A non-compound one is charged on the base
            // alone, which is what most places do.
            $chargeable = $rule->compound ? $base->plus($running) : $base;
            $amount = $rule->charge($chargeable);

            if ($amount->isZero()) {
                continue;
            }

            $running = $running->plus($amount);

            $components[] = new TaxComponent(
                name: $rule->name,
                rate: $rule->percentage(),
                amount: $amount,
                jurisdiction: $this->jurisdictionOf($rule),
            );
        }

        if ($components === []) {
            return TaxResult::none($zero);
        }

        if ($inclusive) {
            // The division and the multiplication disagree by a minor unit or
            // two, and the gross is the number the customer was shown — so the
            // components are made to add up to it rather than the other way
            // round. `allocate` is used because it loses no cent: the remainder
            // goes to the largest share rather than evaporating.
            [$running, $components] = $this->reconcile($supply->amount->minus($base), $components);
        }

        return new TaxResult($running, $components, included: $inclusive);
    }

    /**
     * The amount before tax, given an amount that already contains it.
     *
     * Integer arithmetic in parts per million throughout, because this is money:
     * `gross / (1 + rate)` in floating point is how a price ends up a cent out
     * on every order in one direction.
     *
     * A compounding second level multiplies rather than adds — Quebec's gross is
     * `net x (1 + gst) x (1 + qst)` — so its contribution is `r2 x (1 + r1)`. The
     * integer division there loses a fraction of a part per million, which is why
     * the caller reconciles against the gross afterwards rather than trusting
     * this to be exact.
     *
     * @param  list<TaxRule>  $matched
     */
    private function netOf(Money $gross, array $matched): Money
    {
        $million = 1_000_000;
        $effective = 0;

        $levelOne = 0;

        foreach ($matched as $rule) {
            if ($rule->compound) {
                // Charged on the base plus what is already on it.
                $effective += $rule->rate_ppm + intdiv($rule->rate_ppm * $levelOne, $million);

                continue;
            }

            $effective += $rule->rate_ppm;
            $levelOne += $rule->rate_ppm;
        }

        if ($effective <= 0) {
            return $gross;
        }

        $net = (int) round(($gross->minorUnits * $million) / ($million + $effective));

        return Money::ofMinor($net, $gross->currency);
    }

    /**
     * Make the components add up to the tax that is actually inside the gross.
     *
     * @param  list<TaxComponent>  $components
     * @return array{0: Money, 1: list<TaxComponent>}
     */
    private function reconcile(Money $target, array $components): array
    {
        if (count($components) === 1) {
            return [$target, [$components[0]->withAmount($target)]];
        }

        $weights = array_map(
            static fn (TaxComponent $component): int => $component->amount->minorUnits,
            $components,
        );

        if (array_sum($weights) === 0) {
            return [$target, $components];
        }

        $shares = $target->allocate($weights);

        $reconciled = [];

        foreach ($components as $index => $component) {
            $reconciled[] = $component->withAmount($shares[$index]);
        }

        return [$target, $reconciled];
    }

    /**
     * Whose rules apply: the seller's, resolved the same way document numbers and
     * support departments resolve it.
     */
    private function sellerId(): ?string
    {
        $organizationId = $this->organizations->id();

        return $organizationId === null ? null : $this->sellers->forOrganization($organizationId);
    }

    /**
     * @param  list<TaxRule>  $matched
     */
    private function exemptionFor(array $matched, TaxableSupply $supply): ?string
    {
        if (! $supply->isBusiness || $supply->taxId === null || trim($supply->taxId) === '') {
            return null;
        }

        foreach ($matched as $rule) {
            if (! $rule->exempts_validated_business) {
                continue;
            }

            /*
             * In the seller's own country a registered business still pays: the
             * rule an operator can state without a tax adviser is "a business
             * *elsewhere* accounts for it themselves". When the supply does not
             * say where the seller is, the rule's own country stands in for it.
             */
            $sellerCountry = $supply->supplierCountryCode ?? $rule->country_code;

            if ($sellerCountry !== null
                && $supply->countryCode !== null
                && strtoupper($sellerCountry) === strtoupper($supply->countryCode)) {
                continue;
            }

            return $rule->exemption_note ?? 'reverse_charge';
        }

        return null;
    }

    private function jurisdictionOf(TaxRule $rule): ?string
    {
        $parts = array_values(array_filter([$rule->country_code, $rule->region_code]));

        return $parts === [] ? null : implode('-', $parts);
    }
}
