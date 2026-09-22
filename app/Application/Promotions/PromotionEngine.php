<?php

declare(strict_types=1);

namespace App\Application\Promotions;

use App\Application\Ordering\PricedLine;
use App\Domain\Ordering\LineKind;
use App\Domain\Promotions\PromotionRefusal;
use App\Domain\Promotions\PromotionScope;
use App\Domain\Promotions\PromotionType;
use App\Domain\Shared\Money;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Promotions\Models\Promotion;
use Carbon\CarbonImmutable;

/**
 * Decides whether a code applies, and for how much.
 *
 * One place, used by the cart screen and by order placement, so a code that
 * displays a discount cannot fail to give it — or worse, the reverse.
 *
 * Every refusal is a named reason rather than a boolean. "That code is not
 * valid" sends a customer away when what they needed to hear was that the
 * order has to be ten lira larger.
 */
final readonly class PromotionEngine
{
    /**
     * Look a code up without applying it, for the moment a customer types
     * it in.
     *
     * @return array{promotion: Promotion|null, refusal: PromotionRefusal|null}
     */
    public function find(string $organizationId, string $code): array
    {
        $promotion = Promotion::query()
            ->where('organization_id', $organizationId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (! $promotion instanceof Promotion) {
            return ['promotion' => null, 'refusal' => PromotionRefusal::NotFound];
        }

        return ['promotion' => $promotion, 'refusal' => $this->checkTerms($promotion)];
    }

    /**
     * @param  list<PricedLine>  $lines
     */
    public function apply(Cart $cart, array $lines, Money $zero): DiscountResult
    {
        $code = $cart->promotion_code;

        if ($code === null || $code === '') {
            return DiscountResult::none($lines, $zero);
        }

        $found = $this->find($cart->organization_id, $code);
        $promotion = $found['promotion'];

        if (! $promotion instanceof Promotion) {
            return DiscountResult::none($lines, $zero, $found['refusal']);
        }

        if ($found['refusal'] instanceof PromotionRefusal) {
            return DiscountResult::none($lines, $zero, $found['refusal']);
        }

        if ($promotion->type === PromotionType::Fixed
            && $promotion->amount?->currency->code !== $zero->currency->code) {
            return DiscountResult::none($lines, $zero, PromotionRefusal::WrongCurrency);
        }

        if (($refusal = $this->checkCustomerTerms($promotion, $cart)) instanceof PromotionRefusal) {
            return DiscountResult::none($lines, $zero, $refusal);
        }

        $eligible = $this->eligibleLines($promotion, $lines);

        if ($eligible === []) {
            return DiscountResult::none($lines, $zero, PromotionRefusal::NoEligibleItems);
        }

        $base = $this->eligibleAmount($promotion, $eligible, $zero);
        $subtotal = $this->subtotal($lines, $zero);

        $minimum = $promotion->minimum_subtotal;

        if ($minimum !== null && ! $minimum->isZero() && $minimum->isGreaterThan($subtotal)) {
            return DiscountResult::none($lines, $zero, PromotionRefusal::MinimumNotMet);
        }

        $discount = $this->discountFor($promotion, $base, $zero);

        if ($discount->isZero()) {
            return DiscountResult::none($lines, $zero);
        }

        return new DiscountResult(
            discount: $discount,
            lines: $this->allocate($discount, $eligible, $lines),
            promotionId: $promotion->id,
        );
    }

    /**
     * Terms that do not depend on who is buying.
     */
    private function checkTerms(Promotion $promotion, ?CarbonImmutable $now = null): ?PromotionRefusal
    {
        $now ??= CarbonImmutable::now();

        if (! $promotion->is_active) {
            return PromotionRefusal::Inactive;
        }

        if ($promotion->starts_at !== null && $promotion->starts_at->isAfter($now)) {
            return PromotionRefusal::NotStarted;
        }

        if ($promotion->ends_at !== null && $promotion->ends_at->isBefore($now)) {
            return PromotionRefusal::Expired;
        }

        if ($promotion->usage_limit !== null && $promotion->usage_count >= $promotion->usage_limit) {
            return PromotionRefusal::UsageLimitReached;
        }

        return null;
    }

    /**
     * Terms that depend on the customer holding the cart.
     *
     * Checked here for the message, and again inside the order transaction
     * for the truth: a limit checked only at display time is a race that
     * oversells the last redemption.
     */
    private function checkCustomerTerms(Promotion $promotion, Cart $cart): ?PromotionRefusal
    {
        $customerId = $cart->customer_id;

        if ($customerId === null) {
            // A visitor with no account is new by definition, and has used
            // nothing.
            return null;
        }

        if ($promotion->new_customers_only && $this->hasOrdered($customerId)) {
            return PromotionRefusal::NewCustomersOnly;
        }

        if ($promotion->per_customer_limit !== null) {
            $used = $promotion->redemptions()->where('customer_id', $customerId)->count();

            if ($used >= $promotion->per_customer_limit) {
                return PromotionRefusal::CustomerLimitReached;
            }
        }

        return null;
    }

    private function hasOrdered(string $customerId): bool
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('placed_at')
            ->exists();
    }

    /**
     * @param  list<PricedLine>  $lines
     * @return list<PricedLine>
     */
    private function eligibleLines(Promotion $promotion, array $lines): array
    {
        return array_values(array_filter($lines, function (PricedLine $line) use ($promotion): bool {
            // A discount code is for what the operator sells, not for a
            // registry's fee.
            if ($line->kind === LineKind::Domain) {
                return false;
            }

            if ($line->cycle !== null && ! $promotion->coversCycle($line->cycle)) {
                return false;
            }

            if ($promotion->scope === PromotionScope::SetupFees) {
                return ! $line->lineSetup->isZero();
            }

            if ($promotion->scope === PromotionScope::Products) {
                return $promotion->coversProduct($line->productId);
            }

            return true;
        }));
    }

    /**
     * @param  list<PricedLine>  $eligible
     */
    private function eligibleAmount(Promotion $promotion, array $eligible, Money $zero): Money
    {
        return array_reduce(
            $eligible,
            static fn (Money $carry, PricedLine $line): Money => $carry->plus(
                $promotion->scope === PromotionScope::SetupFees ? $line->lineSetup : $line->lineRecurring,
            ),
            $zero,
        );
    }

    /**
     * @param  list<PricedLine>  $lines
     */
    private function subtotal(array $lines, Money $zero): Money
    {
        return array_reduce(
            $lines,
            static fn (Money $carry, PricedLine $line): Money => $carry->plus($line->lineRecurring),
            $zero,
        );
    }

    private function discountFor(Promotion $promotion, Money $base, Money $zero): Money
    {
        if ($base->isZero() || $base->isNegative()) {
            return $zero;
        }

        $discount = match ($promotion->type) {
            // Once, on the eligible subtotal, rounded half up. Computing it
            // per line and summing rounds several times and produces a
            // total nobody can reconcile.
            PromotionType::Percentage => $base->percentage($promotion->percentage ?? '0'),
            PromotionType::Fixed => $promotion->amount ?? $zero,
        };

        // A discount never exceeds what is being discounted; the change is
        // not ours to give.
        return $discount->isGreaterThan($base) ? $base : $discount;
    }

    /**
     * Spread the discount over the eligible lines so the shares sum exactly
     * to it, weighted by what each line contributed.
     *
     * @param  list<PricedLine>  $eligible
     * @param  list<PricedLine>  $lines
     * @return list<PricedLine>
     */
    private function allocate(Money $discount, array $eligible, array $lines): array
    {
        $weights = array_map(
            static fn (PricedLine $line): int => max($line->lineRecurring->minorUnits + $line->lineSetup->minorUnits, 0),
            $eligible,
        );

        $shares = $discount->allocate(array_values($weights));

        if ($shares === []) {
            return $lines;
        }

        $byItem = [];

        foreach ($eligible as $index => $line) {
            $byItem[$line->itemId] = $shares[$index] ?? null;
        }

        return array_map(
            static fn (PricedLine $line): PricedLine => isset($byItem[$line->itemId]) && $byItem[$line->itemId] instanceof Money
                ? $line->withDiscount($byItem[$line->itemId])
                : $line,
            $lines,
        );
    }
}
