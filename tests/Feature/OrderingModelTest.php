<?php

declare(strict_types=1);

use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\LineKind;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Promotions\PromotionType;
use App\Domain\Shared\Money;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\CartItem;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Ordering\Models\OrderStatusChange;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Infrastructure\Promotions\Models\PromotionRedemption;
use App\Support\Organizations\OrganizationContext;

beforeEach(function (): void {
    $this->provider = Organization::factory()->provider()->create(['name' => 'InfraCMS']);
    $this->reseller = Organization::factory()->reseller($this->provider)->create(['name' => 'Reseller A']);
    $this->context = app(OrganizationContext::class);
});

it('gives a new cart a token and an expiry', function (): void {
    $cart = Cart::factory()->create();

    expect($cart->token)->not->toBeEmpty()
        ->and($cart->expires_at)->not->toBeNull()
        ->and($cart->hasExpired())->toBeFalse();
});

it('knows when a cart has expired', function (): void {
    expect(Cart::factory()->expired()->create()->hasExpired())->toBeTrue();
});

it('keeps addon lines out of the top-level list', function (): void {
    $cart = Cart::factory()->create();
    $product = CartItem::factory()->inCart($cart)->create();

    CartItem::factory()->inCart($cart)->create([
        'parent_id' => $product->id,
        'kind' => LineKind::Addon->value,
        'product_id' => null,
    ]);

    expect($cart->items()->count())->toBe(1)
        ->and($cart->allItems()->count())->toBe(2);
});

it('prices a domain line from its own quote rather than the catalog', function (): void {
    $cart = Cart::factory()->currency('EUR')->create();
    $item = CartItem::factory()->inCart($cart)->domain('example.com', 2, 1200)->create();

    expect($item->kind)->toBe(LineKind::Domain)
        ->and($item->domain_tld)->toBe('com')
        ->and($item->domainRegistration('EUR')?->minorUnits)->toBe(1200);
});

it('upper-cases a promotion code so the lookup stays an equality', function (): void {
    $promotion = Promotion::factory()->create(['code' => ' launch10 ']);

    expect($promotion->code)->toBe('LAUNCH10');
});

it('round-trips a fixed promotion amount as money', function (): void {
    $promotion = Promotion::factory()->fixed(5000, 'TRY')->create();

    expect($promotion->type)->toBe(PromotionType::Fixed)
        ->and($promotion->amount)->toBeInstanceOf(Money::class)
        ->and($promotion->amount?->toDecimalString())->toBe('50.00')
        ->and($promotion->amount?->currency->code)->toBe('TRY');
});

it('keeps a percentage as a string, never a float', function (): void {
    $promotion = Promotion::factory()->percentage('12.50')->create();

    expect($promotion->fresh()?->percentage)->toBeString()
        ->and($promotion->fresh()?->percentage)->toBe('12.50');
});

it('treats a promotion with no cycle list as covering every cycle', function (): void {
    $promotion = Promotion::factory()->create(['billing_cycles' => null]);

    foreach (BillingCycle::cases() as $cycle) {
        expect($promotion->coversCycle($cycle))->toBeTrue();
    }
});

it('honours a promotion cycle list', function (): void {
    $promotion = Promotion::factory()->create([
        'billing_cycles' => [BillingCycle::Annually->value],
    ]);

    expect($promotion->coversCycle(BillingCycle::Annually))->toBeTrue()
        ->and($promotion->coversCycle(BillingCycle::Monthly))->toBeFalse();
});

it('refuses to rewrite a redemption', function (): void {
    PromotionRedemption::factory()->create()->update(['amount_minor' => 1]);
})->throws(RuntimeException::class);

it('refuses to delete a redemption', function (): void {
    PromotionRedemption::factory()->create()->delete();
})->throws(RuntimeException::class);

it('round-trips every order total as money', function (): void {
    $order = Order::factory()->create([
        'currency_code' => 'EUR',
        'subtotal_minor' => 1998,
        'discount_minor' => 200,
        'setup_minor' => 500,
        'tax_minor' => 360,
        'total_minor' => 2658,
        'recurring_total_minor' => 1998,
    ]);

    $fresh = Order::query()->withoutGlobalScope('organization')->findOrFail($order->id);

    expect($fresh->subtotal->toDecimalString())->toBe('19.98')
        ->and($fresh->discount->toDecimalString())->toBe('2.00')
        ->and($fresh->total->toDecimalString())->toBe('26.58')
        ->and($fresh->total->currency->code)->toBe('EUR');
});

it('keeps an order line readable after its product is deleted', function (): void {
    // The record of a sale must not disappear with the thing that was sold.
    $product = Product::factory()->create(['name' => 'Starter Plan']);
    $item = OrderItem::factory()->create([
        'product_id' => $product->id,
        'name' => $product->name,
    ]);

    $product->delete();

    $fresh = OrderItem::query()->withoutGlobalScope('organization')->findOrFail($item->id);

    expect($fresh->exists)->toBeTrue()
        ->and($fresh->name)->toBe('Starter Plan')
        ->and($fresh->product_id)->toBeNull();
});

it('refuses to rewrite order history', function (): void {
    OrderStatusChange::factory()->create()->update(['to_status' => OrderStatus::Paid->value]);
})->throws(RuntimeException::class);

it('refuses to delete order history', function (): void {
    OrderStatusChange::factory()->create()->delete();
})->throws(RuntimeException::class);

it('scopes carts, orders and promotions to their organization', function (): void {
    Cart::factory()->forOrganization($this->provider)->create();
    Cart::factory()->forOrganization($this->reseller)->create();
    Promotion::factory()->forOrganization($this->provider)->create();

    $this->context->runAs($this->reseller->id, function (): void {
        expect(Cart::query()->count())->toBe(1)
            ->and(Promotion::query()->count())->toBe(0)
            ->and(Order::query()->count())->toBe(0);
    });
});
