<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Ordering\Exceptions\CartNotOrderable;
use App\Application\Shared\AllocateNumber;
use App\Domain\Crm\AddressType;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Risk\RiskDecision;
use App\Domain\Shared\Money;
use App\Domain\Tax\TaxableSupply;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Turns a cart into an order.
 *
 * Three things have to be true together or none of them: the order and its
 * lines exist, the promotion is spent, and the cart is gone. One
 * transaction, so a failure halfway does not leave a customer with an order
 * they cannot see or a code they cannot use again.
 *
 * The cart is priced again here rather than trusted from the request. The
 * browser's number is compared against it and a mismatch stops the order.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private PriceCart $pricer,
        private AllocateNumber $numbers,
        private EvaluateOrderRisk $risk,
        private TransitionOrder $transitions,
    ) {}

    public function handle(Cart $cart, PlaceOrderRequest $request, ?Model $actor = null): Order
    {
        $this->assertOrderable($cart, $request);

        $customer = Customer::query()->whereKey($request->customerId)->firstOrFail();

        if (! $customer->status->canTransact()) {
            throw CartNotOrderable::customerCannotOrder();
        }

        $totals = $this->pricer->handle($cart, $this->supplyFor($customer, $cart->currency_code));

        if ($totals->isEmpty()) {
            throw CartNotOrderable::empty();
        }

        $this->assertTotalUnchanged($totals, $request);

        $order = DB::transaction(function () use ($cart, $customer, $request, $totals): Order {
            $order = Order::query()->create([
                'organization_id' => $customer->organization_id,
                'number' => $this->numbers->handle(
                    $customer->organization_id,
                    'order',
                    (string) config('platform.ordering.numbering.prefix', 'ORD-'),
                    (int) config('platform.ordering.numbering.padding', 6),
                ),
                'customer_id' => $customer->id,
                'contact_id' => $request->contactId,
                'status' => OrderStatus::Draft->value,
                'currency_code' => $totals->currencyCode,
                'subtotal_minor' => $totals->subtotal->minorUnits,
                'discount_minor' => $totals->discount->minorUnits,
                'setup_minor' => $totals->setup->minorUnits,
                'tax_minor' => $totals->tax->total->minorUnits,
                'total_minor' => $totals->total->minorUnits,
                'recurring_total_minor' => $totals->recurringTotal->minorUnits,
                'promotion_id' => $totals->promotionId,
                'promotion_code' => $totals->promotionId === null ? null : $totals->promotionCode,
                'tax_breakdown' => $totals->tax->toArray(),
                'tax_exemption_reason' => $totals->tax->exemptionReason,
                'terms_accepted_at' => $request->termsAccepted ? CarbonImmutable::now() : null,
                'terms_version' => (string) config('platform.ordering.terms_version', '1'),
                'ip_address' => $request->ipAddress,
                'user_agent' => $request->userAgent === null ? null : mb_substr($request->userAgent, 0, 512),
                'notes' => $request->notes,
                'placed_at' => CarbonImmutable::now(),
            ]);

            $this->writeLines($order, $totals);
            $this->redeem($order, $totals);

            // The cart has become the order. Leaving it would let the same
            // basket be ordered twice by a second tab.
            $cart->allItems()->delete();
            $cart->delete();

            return $order;
        });

        $this->transitions->record($order, $actor);
        $order = $this->transitions->handle($order, OrderStatus::Pending, $actor);

        Audit::action('ordering.order.placed')
            ->by($actor)
            ->on($order)
            ->forOrganization($order->organization_id)
            ->withMetadata([
                'total' => $totals->total->toDecimalString(),
                'currency' => $totals->currencyCode,
                'lines' => count($totals->lines),
                'promotion' => $order->promotion_code,
                'on_behalf' => $request->onBehalfBy !== null,
            ])
            ->write();

        return $this->decide($order, $request, $actor);
    }

    private function assertOrderable(Cart $cart, PlaceOrderRequest $request): void
    {
        if ($cart->hasExpired()) {
            throw CartNotOrderable::expired();
        }

        if ($cart->isEmpty()) {
            throw CartNotOrderable::empty();
        }

        // An order the desk took is not an order nobody agreed to: the
        // agreement happened on the phone and the audit trail names the
        // operator who recorded it.
        if (! $request->termsAccepted && $request->onBehalfBy === null) {
            throw CartNotOrderable::termsRequired();
        }
    }

    private function assertTotalUnchanged(CartTotals $totals, PlaceOrderRequest $request): void
    {
        if ($request->expectedTotalMinor === null) {
            return;
        }

        if ($request->expectedTotalMinor !== $totals->total->minorUnits) {
            throw CartNotOrderable::totalChanged(
                Money::ofMinor($request->expectedTotalMinor, $totals->currencyCode)->toDecimalString(),
                $totals->total->toDecimalString(),
            );
        }
    }

    private function supplyFor(Customer $customer, string $currency): TaxableSupply
    {
        $address = $customer->addressFor(AddressType::Billing);

        return new TaxableSupply(
            amount: Money::zero($currency),
            countryCode: $address?->country_code,
            stateCode: $address?->region,
            postalCode: $address?->postal_code,
            taxId: $customer->tax_id,
            isBusiness: $customer->tax_id !== null && $customer->tax_id !== '',
            supplierCountryCode: config('platform.tax.flat.country'),
        );
    }

    /**
     * Copy every line onto the order.
     *
     * The names as well as the numbers: this record has to still say the
     * same thing after the product has been renamed, repriced and retired.
     */
    private function writeLines(Order $order, CartTotals $totals): void
    {
        $byCartItem = [];

        foreach ($totals->lines as $position => $line) {
            $parentId = $line->parentItemId === null ? null : ($byCartItem[$line->parentItemId] ?? null);

            $item = $order->allItems()->create([
                'organization_id' => $order->organization_id,
                'parent_id' => $parentId,
                'kind' => $line->kind->value,
                'product_id' => $line->productId,
                'addon_id' => $line->addonId,
                'name' => $line->name,
                'group_name' => $line->groupName,
                'billing_cycle' => $line->cycle?->value,
                'quantity' => $line->quantity,
                'currency_code' => $totals->currencyCode,
                'unit_recurring_minor' => $line->unitRecurring->minorUnits,
                'unit_setup_minor' => $line->unitSetup->minorUnits,
                'line_recurring_minor' => $line->lineRecurring->minorUnits,
                'line_setup_minor' => $line->lineSetup->minorUnits,
                'line_discount_minor' => $line->discount->minorUnits,
                'line_total_minor' => $line->lineTotal->minorUnits,
                'domain' => $line->domain,
                'domain_tld' => $line->domainTld,
                'domain_years' => $line->domainYears,
                'domain_action' => $line->domainAction,
                'domain_addons' => $line->domainAddons === [] ? null : $line->domainAddons,
                'price_override_minor' => $line->priceOverrideMinor,
                'position' => $position,
            ]);

            $byCartItem[$line->itemId] = $item->id;

            $this->writeOptions($order, $item, $line, $totals->currencyCode);
        }
    }

    private function writeOptions(Order $order, OrderItem $item, PricedLine $line, string $currency): void
    {
        foreach ($line->options as $option) {
            $item->options()->create([
                'organization_id' => $order->organization_id,
                'option_group_id' => $option->groupId,
                'option_id' => $option->optionId,
                'group_name' => $option->groupName,
                'group_key' => $option->groupKey,
                'label' => $option->label,
                'value' => $option->value,
                'quantity' => $option->quantity,
                'currency_code' => $currency,
                'recurring_minor' => $option->recurring->minorUnits,
                'setup_minor' => $option->setup->minorUnits,
            ]);
        }
    }

    /**
     * Spend the code.
     *
     * Inside the order transaction and behind a row lock, because a limit
     * checked when the code was typed is a race that oversells the last
     * redemption.
     */
    private function redeem(Order $order, CartTotals $totals): void
    {
        if ($totals->promotionId === null || $totals->discount->isZero()) {
            return;
        }

        $promotion = Promotion::query()
            ->whereKey($totals->promotionId)
            ->lockForUpdate()
            ->first();

        if (! $promotion instanceof Promotion) {
            return;
        }

        if ($promotion->usage_limit !== null && $promotion->usage_count >= $promotion->usage_limit) {
            // Someone took the last one between pricing and placing. The
            // order stands; the discount does not.
            $order->update([
                'promotion_id' => null,
                'promotion_code' => null,
                'discount_minor' => 0,
                'total_minor' => $order->total_minor + $totals->discount->minorUnits,
            ]);

            return;
        }

        $promotion->increment('usage_count');

        // The amount is recorded rather than recomputed: "what did this
        // campaign cost" is a question about money, and a percentage code
        // is worth something different on every order.
        $promotion->redemptions()->create([
            'organization_id' => $order->organization_id,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'amount_minor' => $totals->discount->minorUnits,
            'currency_code' => $totals->currencyCode,
            'redeemed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Ask the risk layer, record the answer, and route the order.
     */
    private function decide(Order $order, PlaceOrderRequest $request, ?Model $actor): Order
    {
        $assessment = $this->risk->handle($order, $request->ipCountry);

        $order->update([
            'risk_decision' => $assessment->decision->value,
            'risk_score' => $assessment->score,
            'risk_reasons' => $assessment->toArray(),
        ]);

        if ($assessment->decision !== RiskDecision::Allow) {
            Audit::action('ordering.order.risk_held')
                ->bySystem('risk')
                ->on($order)
                ->forOrganization($order->organization_id)
                ->withMetadata([
                    'decision' => $assessment->decision->value,
                    'score' => $assessment->score,
                    'reasons' => array_column($assessment->toArray(), 'code'),
                ])
                ->write();
        }

        return match ($assessment->decision) {
            RiskDecision::Deny => $this->transitions->handle(
                $order,
                OrderStatus::Cancelled,
                null,
                'risk:denied',
            ),
            RiskDecision::Review => $this->transitions->handle($order, OrderStatus::FraudReview),
            RiskDecision::Allow => $this->awaitPayment($order, $actor),
        };
    }

    /**
     * An order that costs nothing has nothing to wait for.
     */
    private function awaitPayment(Order $order, ?Model $actor): Order
    {
        $target = $order->total->isZero() ? OrderStatus::Paid : OrderStatus::AwaitingPayment;

        return $this->transitions->handle($order, $target, $actor);
    }
}
