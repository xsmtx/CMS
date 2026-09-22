<?php

declare(strict_types=1);

use App\Application\Ordering\AddToCart;
use App\Application\Ordering\AddToCartRequest;
use App\Application\Ordering\Exceptions\CartNotOrderable;
use App\Application\Ordering\Exceptions\InvalidOrderTransition;
use App\Application\Ordering\PlaceOrder;
use App\Application\Ordering\PlaceOrderRequest;
use App\Application\Ordering\TransitionOrder;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\OptionType;
use App\Domain\Catalog\ProductType;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Ordering\LineKind;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Risk\RiskDecision;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\AddonPrice;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Infrastructure\Promotions\Models\PromotionRedemption;
use App\Support\Organizations\OrganizationContext;

beforeEach(function (): void {
    $this->provider = Organization::factory()->provider()->create();
    $this->audit = $this->fakeAudit();

    $this->customer = Customer::factory()->forOrganization($this->provider)->create();

    $this->product = Product::factory()
        ->ofType(ProductType::Vps)
        ->create(['organization_id' => $this->provider->id, 'name' => 'Starter Plan']);

    ProductPrice::factory()
        ->forProduct($this->product)
        ->cycle(BillingCycle::Monthly)
        ->currency('EUR')
        ->amounts(999, 500)
        ->create();

    $this->cart = Cart::factory()->forOrganization($this->provider)->currency('EUR')->create([
        'customer_id' => $this->customer->id,
    ]);
});

function fill(?BillingCycle $cycle = null, int $quantity = 1): void
{
    app(AddToCart::class)->handle(test()->cart->fresh() ?? test()->cart, new AddToCartRequest(
        productId: test()->product->id,
        cycle: $cycle ?? BillingCycle::Monthly,
        quantity: $quantity,
    ));
}

function place(?PlaceOrderRequest $request = null): Order
{
    return app(PlaceOrder::class)->handle(
        test()->cart->fresh() ?? test()->cart,
        $request ?? new PlaceOrderRequest(
            customerId: test()->customer->id,
            termsAccepted: true,
        ),
    );
}

it('turns a cart into an order awaiting payment', function (): void {
    fill();

    $order = place();

    expect($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->total->toDecimalString())->toBe('14.99')
        ->and($order->recurring_total->toDecimalString())->toBe('9.99')
        ->and($order->placed_at)->not->toBeNull()
        ->and($this->audit->actions())->toContain('ordering.order.placed');
});

it('gives the order a sequential number', function (): void {
    fill();
    $first = place();

    $this->cart = Cart::factory()->forOrganization($this->provider)->currency('EUR')->create([
        'customer_id' => $this->customer->id,
    ]);
    fill();
    $second = place();

    expect($first->number)->toBe('ORD-000001')
        ->and($second->number)->toBe('ORD-000002');
});

it('empties the cart so the same basket cannot be ordered twice', function (): void {
    fill();
    $cartId = $this->cart->id;

    place();

    expect(Cart::query()->withoutGlobalScope('organization')->whereKey($cartId)->exists())->toBeFalse();
});

it('copies the product name onto the line rather than referencing it', function (): void {
    fill();
    $order = place();

    $this->product->update(['name' => 'Renamed Plan']);

    $line = $order->allItems()->first();

    expect($line?->name)->toBe('Starter Plan')
        ->and($line?->unit_recurring->toDecimalString())->toBe('9.99');
});

it('copies option wording and amounts onto the line', function (): void {
    $group = OptionGroup::factory()->forProduct($this->product)->create([
        'name' => 'Control panel',
        'key' => 'control_panel',
        'type' => OptionType::Select->value,
    ]);

    $option = Option::factory()->inGroup($group)->create(['label' => 'cPanel', 'value' => 'cpanel']);

    $option->prices()->create([
        'organization_id' => $option->organization_id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 500,
        'setup_minor' => 0,
    ]);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        options: [$group->id => ['option_id' => $option->id]],
    ));

    $order = place();

    $option->update(['label' => 'cPanel (legacy)']);

    $copied = $order->allItems()->first()?->options()->first();

    expect($copied?->label)->toBe('cPanel')
        ->and($copied?->group_name)->toBe('Control panel')
        ->and($copied?->recurring->toDecimalString())->toBe('5.00');
});

it('keeps an addon hanging off the line it was bought with', function (): void {
    $addon = Addon::factory()->forProduct($this->product)->create(['name' => 'Dedicated IP']);

    AddonPrice::factory()->create([
        'addon_id' => $addon->id,
        'organization_id' => $addon->organization_id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 200,
        'setup_minor' => 0,
    ]);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        addonIds: [$addon->id],
    ));

    $order = place();

    $parent = $order->items()->first();
    $child = $order->allItems()->where('kind', LineKind::Addon->value)->first();

    expect($order->items()->count())->toBe(1)
        ->and($order->allItems()->count())->toBe(2)
        ->and($child?->parent_id)->toBe($parent?->id);
});

it('records the first status without pretending it came from somewhere', function (): void {
    fill();
    $order = place();

    $history = $order->statusHistory()->get();

    expect($history->first()?->from_status)->toBeNull()
        ->and($history->first()?->to_status)->toBe(OrderStatus::Draft)
        ->and($history->last()?->to_status)->toBe(OrderStatus::AwaitingPayment);
});

it('marks a free order paid because there is nothing to wait for', function (): void {
    $free = Product::factory()->ofType(ProductType::Service)
        ->create(['organization_id' => $this->provider->id]);

    ProductPrice::factory()->forProduct($free)->currency('EUR')->amounts(0, 0)->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $free->id,
        cycle: BillingCycle::Monthly,
    ));

    expect(place()->status)->toBe(OrderStatus::Paid);
});

it('refuses an empty cart', function (): void {
    place();
})->throws(CartNotOrderable::class);

it('refuses an order with the terms unaccepted', function (): void {
    fill();

    place(new PlaceOrderRequest(customerId: $this->customer->id, termsAccepted: false));
})->throws(CartNotOrderable::class);

it('refuses an expired cart', function (): void {
    fill();
    $this->cart->update(['expires_at' => now()->subDay()]);

    place();
})->throws(CartNotOrderable::class);

it('stops the order when the price moved while the customer was reading', function (): void {
    fill();

    // The operator raises the price between the summary and the button.
    $this->product->prices()->where('billing_cycle', BillingCycle::Monthly->value)
        ->update(['recurring_minor' => 1299]);

    place(new PlaceOrderRequest(
        customerId: $this->customer->id,
        termsAccepted: true,
        expectedTotalMinor: 1499,
    ));
})->throws(CartNotOrderable::class);

it('places the order when the total is what the browser showed', function (): void {
    fill();

    $order = place(new PlaceOrderRequest(
        customerId: $this->customer->id,
        termsAccepted: true,
        expectedTotalMinor: 1499,
    ));

    expect($order->total->minorUnits)->toBe(1499);
});

it('refuses an order from a customer that cannot transact', function (): void {
    fill();
    $this->customer->update(['status' => CustomerStatus::Suspended->value]);

    place();
})->throws(CartNotOrderable::class);

it('spends the promotion and records what it cost', function (): void {
    $promotion = Promotion::factory()->forOrganization($this->provider)
        ->code('SAVE10')->percentage('10')->create(['usage_limit' => 5]);

    fill();
    $this->cart->update(['promotion_code' => 'SAVE10']);

    $order = place();

    $redemption = PromotionRedemption::query()->withoutGlobalScope('organization')->first();

    expect($promotion->fresh()?->usage_count)->toBe(1)
        ->and($order->discount->toDecimalString())->toBe('1.00')
        ->and($redemption?->amount->toDecimalString())->toBe('1.00')
        ->and($redemption?->order_id)->toBe($order->id);
});

it('places the order without the discount when the last redemption was taken first', function (): void {
    // Two customers, one redemption left. The second order stands; the
    // discount does not, and the customer pays the undiscounted total.
    $promotion = Promotion::factory()->forOrganization($this->provider)
        ->code('LASTONE')->percentage('10')->create(['usage_limit' => 1]);

    fill();
    $this->cart->update(['promotion_code' => 'LASTONE']);

    // Not fillable on purpose: the count is maintained by redemption, not
    // by whoever is editing the promotion.
    $promotion->increment('usage_count');

    $order = place();

    expect($order->discount->isZero())->toBeTrue()
        ->and($order->total->toDecimalString())->toBe('14.99')
        ->and($order->promotion_code)->toBeNull();
});

it('records the risk decision on the order', function (): void {
    fill();

    expect(place()->risk_decision)->toBe(RiskDecision::Allow);
});

it('holds an order the risk rules flagged', function (): void {
    config()->set('platform.risk.rules.high_value_minor', 1000);
    config()->set('platform.risk.rules.review_score', 2);

    fill();
    $order = place();

    expect($order->status)->toBe(OrderStatus::FraudReview)
        ->and($order->risk_decision)->toBe(RiskDecision::Review)
        ->and($order->risk_reasons[0]['code'] ?? null)->toBe('high_order_value')
        ->and($this->audit->actions())->toContain('ordering.order.risk_held');
});

it('cancels an order the risk rules denied', function (): void {
    config()->set('platform.risk.rules.high_value_minor', 1000);
    config()->set('platform.risk.rules.deny_score', 2);

    fill();
    $order = place();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->risk_decision)->toBe(RiskDecision::Deny);
});

it('lets an operator move an order through the machine, with a reason', function (): void {
    fill();
    $order = place();

    app(TransitionOrder::class)->handle($order, OrderStatus::Cancelled, null, 'Customer changed their mind');

    expect($order->fresh()?->status)->toBe(OrderStatus::Cancelled)
        ->and($order->statusHistory()->get()->last()?->reason)->toBe('Customer changed their mind')
        ->and($this->audit->actions())->toContain('ordering.order.status_changed');
});

it('refuses a transition the machine does not allow', function (): void {
    fill();
    $order = place();

    app(TransitionOrder::class)->handle($order, OrderStatus::Refunded);
})->throws(InvalidOrderTransition::class);

it('does not reach a cart belonging to another organization', function (): void {
    $reseller = Organization::factory()->reseller($this->provider)->create();
    $theirs = Cart::factory()->forOrganization($reseller)->create();

    app(OrganizationContext::class)->runAs($this->provider->id, function () use ($theirs): void {
        expect(Cart::query()->whereKey($theirs->id)->exists())->toBeTrue();
    });

    app(OrganizationContext::class)->runAs($reseller->id, function (): void {
        expect(Cart::query()->where('organization_id', $this->provider->id)->exists())->toBeFalse();
    });
});
