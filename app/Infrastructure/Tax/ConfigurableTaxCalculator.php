<?php

declare(strict_types=1);

namespace App\Infrastructure\Tax;

use App\Application\Shared\ResolveSeller;
use App\Application\Tax\TaxRules;
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
 * What it deliberately does **not** do is round. Per line or per invoice is
 * `TaxSetting`'s answer and belongs to whatever assembles the document; a
 * calculator handed one supply at a time that also rounded would round twice.
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

        $base = $supply->amount;
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

        return new TaxResult($running, $components);
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
