<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Ordering\Exceptions\PriceUnavailable;
use App\Application\Promotions\PromotionEngine;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\LineKind;
use App\Domain\Shared\Money;
use App\Domain\Tax\Contracts\TaxCalculator;
use App\Domain\Tax\TaxableSupply;
use App\Domain\Tax\TaxResult;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\CartItem;
use App\Infrastructure\Ordering\Models\CartItemOption;

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
final readonly class PriceCart
{
    public function __construct(
        private PromotionEngine $promotions,
        private TaxCalculator $tax,
    ) {}

    public function handle(Cart $cart, ?TaxableSupply $supply = null): CartTotals
    {
        $currency = $cart->currency_code;
        $zero = Money::zero($currency);

        $cart->load([
            'allItems.product.prices',
            // The line copies the group name, so the group has to be here
            // rather than fetched one product at a time.
            'allItems.product.group',
            'allItems.addon.prices',
            'allItems.options.option.prices',
            'allItems.options.group',
        ]);

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
            : $this->tax->calculate(new TaxableSupply(
                amount: $taxable,
                countryCode: $supply->countryCode,
                stateCode: $supply->stateCode,
                postalCode: $supply->postalCode,
                taxId: $supply->taxId,
                isBusiness: $supply->isBusiness,
                supplierCountryCode: $supply->supplierCountryCode,
            ));

        $recurring = $this->sum(
            array_map(
                static fn (PricedLine $line): Money => $line->lineRecurring,
                array_values(array_filter($lines, static fn (PricedLine $line): bool => $line->isRecurring())),
            ),
            $zero,
        );

        return new CartTotals(
            currencyCode: $currency,
            lines: $lines,
            subtotal: $subtotal,
            setup: $setup,
            discount: $discountResult->discount,
            tax: $tax,
            total: $taxable->plus($tax->total),
            recurringTotal: $recurring,
            promotionCode: $cart->promotion_code,
            promotionId: $discountResult->promotionId,
            promotionRefusal: $discountResult->refusal,
        );
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

        $recurring = $product->recurringFor($cycle, $currency);

        if ($recurring === null) {
            throw PriceUnavailable::for($product->name, $cycle, $currency);
        }

        $setup = $product->setupFor($cycle, $currency) ?? Money::zero($currency);

        $options = [];

        foreach ($item->options as $choice) {
            $priced = $this->priceOption($choice, $cycle, $currency);

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

        return $this->assemble(
            $item,
            $addon->name,
            null,
            $cycle,
            $recurring,
            $addon->setupFor($cycle, $currency) ?? Money::zero($currency),
            [],
        );
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

    private function priceOption(CartItemOption $choice, BillingCycle $cycle, string $currency): ?PricedOption
    {
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
            recurring: $recurring->multipliedBy($quantity),
            setup: $setup->multipliedBy($quantity),
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
