<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Ordering\Exceptions\PriceUnavailable;
use App\Application\Promotions\PromotionEngine;
use App\Application\Resellers\ResolveSellingPrice;
use App\Application\Tax\CurrentTaxSettings;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\LineKind;
use App\Domain\Shared\Money;
use App\Domain\Tax\Contracts\TaxCalculator;
use App\Domain\Tax\TaxableSupply;
use App\Domain\Tax\TaxAppliesTo;
use App\Domain\Tax\TaxCategory;
use App\Domain\Tax\TaxResult;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\CartItem;
use App\Infrastructure\Ordering\Models\CartItemOption;
use App\Support\Organizations\OrganizationContext;

/**
 * Works out what a cart costs.
 *
 * The one place that composes a price out of a product, its options, its
 * addons, a promotion and tax. The cart screen, the checkout summary and
 * the order that gets written all call this, so none of them can disagree
 * about the total — which is the failure mode that has customers charged
 * something other than what they were shown.
 *
 * Nothing is converted between currencies and nothing is rounded twice: the
 * discount is computed once on the eligible subtotal, then allocated across
 * lines with a largest-remainder split so the parts sum exactly to the
 * whole.
 */
final class PriceCart
{
    /**
     * Product line id => the markup on it, for this cart.
     *
     * Not readonly for this: the map is per call and is rebuilt at the top
     * of `handle()`, because a cart is priced on every read and a map left
     * over from the last one would be a price from somebody else's cart.
     *
     * @var array<string, string|null>
     */
    private array $margins = [];

    public function __construct(
        private readonly PromotionEngine $promotions,
        private readonly TaxCalculator $tax,
        private readonly ResolveSellingPrice $sellingPrices,
        private readonly OrganizationContext $organizations,
        private readonly CurrentTaxSettings $taxSettings,
    ) {}

    public function handle(Cart $cart, ?TaxableSupply $supply = null): CartTotals
    {
        $currency = $cart->currency_code;
        $zero = Money::zero($currency);

        // Outside the boundary, deliberately. **The catalogue belongs to the
        // provider**, and a cart being priced for a reseller's customer is
        // nowhere near it in the tree — a scoped eager load returns a line
        // whose product is null, and the symptom is "price unavailable" for
        // a product that is plainly on sale.
        //
        // Whether the seller may offer a product was decided when the line
        // was added (`AddToCart` asks `ResellerCatalogue`). Pricing a line
        // that already exists must not re-decide it: a product withdrawn
        // while somebody had it in their basket should still total up, or
        // checkout breaks halfway with nothing the customer can do.
        $this->organizations->withoutBoundary(static function () use ($cart): void {
            $cart->load([
                'allItems.product.prices',
                // The line copies the group name, so the group has to be
                // here rather than fetched one product at a time.
                'allItems.product.group',
                'allItems.addon.prices',
                'allItems.options.option.prices',
                'allItems.options.group',
            ]);
        });

        // The markup per product line, worked out once and then read by the
        // addon lines hanging off it. A `parent()` relation would have been
        // a query per addon, and strict mode only reports that once a cart
        // holds two of them.
        $this->margins = [];

        $lines = [];

        foreach ($cart->allItems as $item) {
            $lines[] = $this->priceLine($item, $currency);
        }

        $subtotal = $this->sum(array_map(static fn (PricedLine $line): Money => $line->lineRecurring, $lines), $zero);
        $setup = $this->sum(array_map(static fn (PricedLine $line): Money => $line->lineSetup, $lines), $zero);

        $discountResult = $this->promotions->apply($cart, $lines, $zero);
        $lines = $discountResult->lines;

        $taxable = $subtotal->plus($setup)->minus($discountResult->discount);
        $tax = $supply === null
            ? TaxResult::none($zero)
            : $this->taxFor($lines, $supply, $zero);

        $recurringLines = array_values(array_filter(
            $lines,
            static fn (PricedLine $line): bool => $line->isRecurring(),
        ));

        $recurring = $this->sum(
            array_map(static fn (PricedLine $line): Money => $line->lineRecurring, $recurringLines),
            $zero,
        );

        /*
         * What the renewal invoice will say, which is the only figure worth
         * quoting as "then". A renewal is taxed exactly like the first
         * invoice, so showing the net here told a customer their monthly
         * price was a fifth lower than it is. An inclusive catalog needs no
         * addition: the price already is the gross.
         *
         * Taxed on the recurring amount alone — a setup fee is charged once,
         * so taxing it into the monthly figure overstates the renewal by the
         * tax on a fee that will never be charged again.
         */
        $recurringTax = $supply === null || $recurringLines === []
            ? TaxResult::none($zero)
            : $this->taxFor($recurringLines, $supply, $zero, onRecurring: true);

        $recurringWithTax = $recurringTax->included
            ? $recurring
            : $recurring->plus($recurringTax->total);

        /*
         * Nothing is added when the price already includes the tax. An inclusive
         * catalog price is the price the customer was shown, so the total is the
         * amount itself and the tax is a figure *inside* it.
         *
         * The subtotal then carries the net, which is what keeps the document's
         * own arithmetic true — subtotal + setup - discount + tax = total holds
         * either way, and every consumer of `CartTotals` (the cart screen, the
         * order rows, the invoice copied from them) needs no knowledge of which
         * kind of catalog this is.
         */
        return new CartTotals(
            currencyCode: $currency,
            lines: $lines,
            subtotal: $tax->included ? $subtotal->minus($tax->total) : $subtotal,
            setup: $setup,
            discount: $discountResult->discount,
            tax: $tax,
            total: $tax->included ? $taxable : $taxable->plus($tax->total),
            recurringTotal: $recurring,
            recurringWithTax: $recurringWithTax,
            promotionCode: $cart->promotion_code,
            promotionId: $discountResult->promotionId,
            promotionRefusal: $discountResult->refusal,
        );
    }

    /**
     * The tax on these lines.
     *
     * **Per line, not on one total**, and that is what makes two configurable
     * things real rather than decorative. A rule may be scoped to products, to
     * domains or to addons, because several countries tax a domain registration
     * and a hosting account differently — and until this, the only supply ever
     * handed to the calculator said "all", so a rule scoped to anything else
     * could never match. And `TaxRounding` had nothing to decide, because there
     * was only ever one calculation to round.
     *
     * Rounding per line means one calculation per line. Rounding once on the
     * invoice means one per **tax treatment**: a cart whose lines are all
     * products is a single calculation, exactly as before, and a cart that mixes
     * a domain with hosting is two because two different rates cannot share one
     * rounding.
     *
     * A line's own amount is `lineTotal` — what it renews for, plus its setup
     * fee, less its share of the discount — so the parts add up to the taxable
     * total by construction rather than by a second calculation that could
     * disagree with it.
     *
     * @param  list<PricedLine>  $lines
     */
    private function taxFor(
        array $lines,
        TaxableSupply $supply,
        Money $zero,
        bool $onRecurring = false,
    ): TaxResult {
        $parts = $this->taxSettings->roundsPerLine()
            ? array_map(
                static fn (PricedLine $line): array => [
                    TaxCategory::of($line->kind),
                    $onRecurring ? $line->lineRecurring : $line->lineTotal,
                ],
                $lines,
            )
            : $this->byTreatment($lines, $zero, $onRecurring);

        $total = $zero;
        $components = [];
        $exemption = null;
        $included = false;

        foreach ($parts as [$appliesTo, $amount]) {
            if ($amount->isZero() || $amount->isNegative()) {
                continue;
            }

            $result = $this->tax->calculate(new TaxableSupply(
                amount: $amount,
                countryCode: $supply->countryCode,
                stateCode: $supply->stateCode,
                postalCode: $supply->postalCode,
                taxId: $supply->taxId,
                isBusiness: $supply->isBusiness,
                supplierCountryCode: $supply->supplierCountryCode,
                appliesTo: $appliesTo,
            ));

            $total = $total->plus($result->total);
            $included = $included || $result->included;
            $exemption ??= $result->exemptionReason;

            foreach ($result->components as $component) {
                $key = $component->name.'|'.$component->rate.'|'.($component->jurisdiction ?? '');

                $components[$key] = array_key_exists($key, $components)
                    ? $components[$key]->withAmount($components[$key]->amount->plus($component->amount))
                    : $component;
            }
        }

        return new TaxResult($total, array_values($components), $exemption, $included);
    }

    /**
     * The lines summed by how they are taxed.
     *
     * One entry per kind that is actually in the cart, so a cart of hosting is
     * one calculation and nothing about an ordinary order changes.
     *
     * @param  list<PricedLine>  $lines
     * @return list<array{0: TaxAppliesTo, 1: Money}>
     */
    private function byTreatment(array $lines, Money $zero, bool $onRecurring = false): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $category = TaxCategory::of($line->kind);
            $key = $category->value;
            $amount = $onRecurring ? $line->lineRecurring : $line->lineTotal;

            $totals[$key] = [$category, ($totals[$key][1] ?? $zero)->plus($amount)];
        }

        return array_values($totals);
    }

    private function priceLine(CartItem $item, string $currency): PricedLine
    {
        return match ($item->kind) {
            LineKind::Domain => $this->priceDomain($item, $currency),
            LineKind::Addon => $this->priceAddon($item, $currency),
            LineKind::Product => $this->priceProduct($item, $currency),
        };
    }

    private function priceProduct(CartItem $item, string $currency): PricedLine
    {
        $product = $item->product;
        $cycle = $item->billing_cycle;

        if ($product === null || $cycle === null) {
            throw PriceUnavailable::for($item->id, $cycle, $currency);
        }

        // Not `$product->recurringFor()` directly: when the seller is a
        // reseller, what the customer pays is the reseller's number. One
        // place works that out (`ResolveSellingPrice`) so the cart, the
        // storefront and the admin order form cannot disagree.
        $price = $this->sellingPrices->resolve($product, $cycle, $currency);

        if ($price === null) {
            throw PriceUnavailable::for($product->name, $cycle, $currency);
        }

        $recurring = $price->recurring;
        $setup = $price->setup;

        // The plan's markup, carried onto its extras: an addon or an option
        // is sold with the plan, and one that went out at cost would be a
        // discount nobody agreed to.
        $margin = $price->marginPercent;

        // Remembered for the addon lines, which are priced later in the same
        // pass and carry the plan's markup rather than one of their own.
        $this->margins[$item->id] = $margin;

        $options = [];

        foreach ($item->options as $choice) {
            $priced = $this->priceOption($choice, $cycle, $currency, $margin);

            if ($priced === null) {
                continue;
            }

            $options[] = $priced;
            $recurring = $recurring->plus($priced->recurring);
            $setup = $setup->plus($priced->setup);
        }

        return $this->assemble($item, $product->name, $product->group?->name, $cycle, $recurring, $setup, $options);
    }

    private function priceAddon(CartItem $item, string $currency): PricedLine
    {
        $addon = $item->addon;

        // An addon is billed on the cycle of the product it was bought
        // with, which is why the line carries it rather than the addon.
        $cycle = $item->billing_cycle;

        if ($addon === null || $cycle === null) {
            throw PriceUnavailable::for($item->id, $cycle, $currency);
        }

        $recurring = $addon->recurringFor($cycle, $currency);

        if ($recurring === null) {
            throw PriceUnavailable::for($addon->name, $cycle, $currency);
        }

        // The markup belongs to the plan this addon hangs off, which is the
        // parent line. An addon has no margin of its own to look up.
        $margin = $this->marginOfParent($item);

        return $this->assemble(
            $item,
            $addon->name,
            null,
            $cycle,
            $this->sellingPrices->markUp($recurring, $margin),
            $this->sellingPrices->markUp(
                $addon->setupFor($cycle, $currency) ?? Money::zero($currency),
                $margin,
            ),
            [],
        );
    }

    /**
     * The markup on the plan an addon line was bought with.
     *
     * A domain line has no parent and no markup: a domain is priced from the
     * TLD matrix and written onto the line when it is added (ADR 0021), so
     * there is nothing here to mark up.
     */
    private function marginOfParent(CartItem $item): ?string
    {
        return $item->parent_id === null ? null : ($this->margins[$item->parent_id] ?? null);
    }

    /**
     * A domain is paid for a term up front. It renews, but on its own
     * schedule and its own invoice, so it is not part of what the
     * subscription costs every cycle.
     */
    private function priceDomain(CartItem $item, string $currency): PricedLine
    {
        $registration = $item->domainRegistration($currency);

        if ($registration === null) {
            throw PriceUnavailable::for((string) $item->domain, null, $currency);
        }

        return $this->assemble(
            $item,
            (string) $item->domain,
            null,
            null,
            $registration,
            Money::zero($currency),
            [],
        );
    }

    private function priceOption(
        CartItemOption $choice,
        BillingCycle $cycle,
        string $currency,
        ?string $marginPercent = null,
    ): ?PricedOption {
        $group = $choice->group;

        if ($group === null) {
            return null;
        }

        $option = $choice->option;
        $quantity = max($choice->quantity, 1);

        // A quantity option has no chosen row: the group itself is priced,
        // and the number the customer typed multiplies it.
        $source = $option ?? $group->options->first();

        $recurring = $source instanceof Option
            ? ($source->recurringFor($cycle, $currency) ?? Money::zero($currency))
            : Money::zero($currency);

        $setup = $source instanceof Option
            ? ($source->setupFor($cycle, $currency) ?? Money::zero($currency))
            : Money::zero($currency);

        return new PricedOption(
            groupId: $group->id,
            groupName: $group->name,
            groupKey: $group->key,
            optionId: $option?->id,
            // A quantity option has no chosen row, so the number the
            // customer typed is what the line says was chosen.
            label: $option instanceof Option ? $option->label : (string) $quantity,
            value: $option instanceof Option ? $option->value : (string) $quantity,
            quantity: $quantity,
            recurring: $this->sellingPrices->markUp($recurring, $marginPercent)->multipliedBy($quantity),
            setup: $this->sellingPrices->markUp($setup, $marginPercent)->multipliedBy($quantity),
        );
    }

    /**
     * @param  list<PricedOption>  $options
     */
    private function assemble(
        CartItem $item,
        string $name,
        ?string $groupName,
        ?BillingCycle $cycle,
        Money $unitRecurring,
        Money $unitSetup,
        array $options,
    ): PricedLine {
        $quantity = max($item->quantity, 1);

        // The one place a unit price becomes a line, which is why the
        // override is applied here rather than at each caller. It replaces
        // the recurring unit price and leaves setup alone: an operator
        // agreeing a monthly rate has not agreed to waive the setup fee.
        $override = $item->priceOverride($unitRecurring->currency->code);

        if ($override instanceof Money) {
            $unitRecurring = $override;
        }

        $lineRecurring = $unitRecurring->multipliedBy($quantity);
        $lineSetup = $unitSetup->multipliedBy($quantity);
        $zero = Money::zero($unitRecurring->currency);

        return new PricedLine(
            itemId: $item->id,
            kind: $item->kind,
            name: $name,
            groupName: $groupName,
            cycle: $cycle,
            quantity: $quantity,
            unitRecurring: $unitRecurring,
            unitSetup: $unitSetup,
            lineRecurring: $lineRecurring,
            lineSetup: $lineSetup,
            discount: $zero,
            lineTotal: $lineRecurring->plus($lineSetup),
            options: $options,
            productId: $item->product_id,
            addonId: $item->addon_id,
            parentItemId: $item->parent_id,
            domain: $item->domain,
            domainTld: $item->domain_tld,
            domainYears: $item->domain_years,
            domainAction: $item->domain_action,
            domainAddons: $item->domainAddons(),
            priceOverrideMinor: $override instanceof Money ? $override->minorUnits : null,
        );
    }

    /**
     * @param  list<Money>  $amounts
     */
    private function sum(array $amounts, Money $zero): Money
    {
        return array_reduce($amounts, static fn (Money $carry, Money $amount): Money => $carry->plus($amount), $zero);
    }
}
