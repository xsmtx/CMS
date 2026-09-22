<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Application\Ordering\CartTotals;
use App\Application\Ordering\PricedLine;
use App\Application\Ordering\PricedOption;

/**
 * Turns totals into the strings a template prints.
 *
 * Formatting happens here, once, so a template never reasons about a
 * currency and the cart and the checkout summary cannot format the same
 * number two different ways.
 */
trait PresentsCartTotals
{
    /**
     * @return array<string, mixed>
     */
    protected function present(CartTotals $totals): array
    {
        $locale = app()->getLocale();

        return [
            'currency' => $totals->currencyCode,
            'empty' => $totals->isEmpty(),
            'lines' => array_values(array_map(
                fn (PricedLine $line): array => $this->line($line),
                array_filter($totals->lines, static fn (PricedLine $line): bool => $line->parentItemId === null),
            )),
            'addonLines' => array_values(array_map(
                fn (PricedLine $line): array => $this->line($line),
                array_filter($totals->lines, static fn (PricedLine $line): bool => $line->parentItemId !== null),
            )),
            'subtotal' => $totals->subtotal->format($locale),
            'setup' => $totals->hasSetupFees() ? $totals->setup->format($locale) : null,
            'discount' => $totals->hasDiscount() ? $totals->discount->format($locale) : null,
            'tax' => $totals->tax->isZero() ? null : $totals->tax->total->format($locale),
            'taxName' => $totals->tax->components[0]->name ?? null,
            'total' => $totals->total->format($locale),
            'totalMinor' => $totals->total->minorUnits,
            'recurringTotal' => $totals->recurringTotal->isZero()
                ? null
                : $totals->recurringTotal->format($locale),
            'promotionCode' => $totals->promotionCode,
            'promotionApplied' => $totals->promotionId !== null,
            'promotionRefusal' => $totals->promotionRefusal === null
                ? null
                : (string) __($totals->promotionRefusal->messageKey(), [
                    'currency' => $totals->currencyCode,
                    'minimum' => '',
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(PricedLine $line): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $line->itemId,
            'parentId' => $line->parentItemId,
            'kind' => $line->kind->value,
            'name' => $line->name,
            'groupName' => $line->groupName,
            'cycle' => $line->cycle?->value,
            'cycleLabel' => $line->cycle === null ? null : (string) __('catalog.cycles.'.$line->cycle->value),
            'cycleSuffix' => $line->cycle === null ? null : (string) __('catalog.cycle_short.'.$line->cycle->value),
            'quantity' => $line->quantity,
            'unitRecurring' => $line->unitRecurring->format($locale),
            'lineSetup' => $line->lineSetup->isZero() ? null : $line->lineSetup->format($locale),
            'lineDiscount' => $line->discount->isZero() ? null : $line->discount->format($locale),
            'lineTotal' => $line->lineTotal->format($locale),
            'domain' => $line->domain,
            'options' => array_map(
                static fn (PricedOption $option): array => [
                    'group' => $option->groupName,
                    'label' => $option->label,
                    'amount' => $option->recurring->isZero() ? null : $option->recurring->format($locale),
                ],
                $line->options,
            ),
        ];
    }
}
