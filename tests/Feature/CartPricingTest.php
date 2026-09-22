<?php

declare(strict_types=1);

use App\Application\Ordering\AddToCart;
use App\Application\Ordering\AddToCartRequest;
use App\Application\Ordering\CartTotals;
use App\Application\Ordering\Exceptions\InvalidCartItem;
use App\Application\Ordering\Exceptions\PriceUnavailable;
use App\Application\Ordering\Exceptions\ProductNotOrderable;
use App\Application\Ordering\PriceCart;
use App\Application\Ordering\PricedLine;
use App\Application\Ordering\UpdateCartItem;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\OptionType;
use App\Domain\Catalog\ProductType;
use App\Domain\Promotions\PromotionRefusal;
use App\Domain\Promotions\PromotionScope;
use App\Domain\Shared\Money;
use App\Domain\Tax\TaxableSupply;
use App\Infrastructure\Catalog\Models\Addon;
use App\Infrastructure\Catalog\Models\AddonPrice;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Promotions\Models\Promotion;

beforeEach(function (): void {
    $this->provider = Organization::factory()->provider()->create();

    $this->product = Product::factory()
        ->ofType(ProductType::Vps)
        ->create(['organization_id' => $this->provider->id, 'name' => 'Starter Plan']);

    ProductPrice::factory()
        ->forProduct($this->product)
        ->cycle(BillingCycle::Monthly)
        ->currency('EUR')
        ->amounts(999, 500)
        ->create();

    ProductPrice::factory()
        ->forProduct($this->product)
        ->cycle(BillingCycle::Annually)
        ->currency('EUR')
        ->amounts(9990, 0)
        ->create();

    $this->cart = Cart::factory()->forOrganization($this->provider)->currency('EUR')->create();
});

function priceCart(Cart $cart, ?TaxableSupply $supply = null): CartTotals
{
    return app(PriceCart::class)->handle($cart->fresh() ?? $cart, $supply);
}

it('prices a plain product line', function (): void {
    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $totals = priceCart($this->cart);

    expect($totals->subtotal->toDecimalString())->toBe('9.99')
        ->and($totals->setup->toDecimalString())->toBe('5.00')
        ->and($totals->total->toDecimalString())->toBe('14.99')
        // The setup fee is charged once; what renews is the plan alone.
        ->and($totals->recurringTotal->toDecimalString())->toBe('9.99');
});

it('multiplies a line by its quantity', function (): void {
    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        quantity: 3,
    ));

    $totals = priceCart($this->cart);

    expect($totals->subtotal->toDecimalString())->toBe('29.97')
        ->and($totals->setup->toDecimalString())->toBe('15.00')
        ->and($totals->total->toDecimalString())->toBe('44.97');
});

it('adds a signed option delta to the line', function (): void {
    $group = OptionGroup::factory()->forProduct($this->product)->create([
        'name' => 'Control panel',
        'key' => 'control_panel',
        'type' => OptionType::Select->value,
        'is_required' => true,
    ]);

    $cpanel = Option::factory()->inGroup($group)->create(['label' => 'cPanel', 'value' => 'cpanel']);
    $none = Option::factory()->inGroup($group)->create(['label' => 'None', 'value' => 'none']);

    $cpanel->prices()->create([
        'organization_id' => $cpanel->organization_id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 500,
        'setup_minor' => 0,
    ]);

    $none->prices()->create([
        'organization_id' => $none->organization_id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => -300,
        'setup_minor' => 0,
    ]);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        options: [$group->id => ['option_id' => $cpanel->id]],
    ));

    expect(priceCart($this->cart)->subtotal->toDecimalString())->toBe('14.99');

    app(UpdateCartItem::class)->empty($this->cart);

    app(AddToCart::class)->handle($this->cart->fresh() ?? $this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        options: [$group->id => ['option_id' => $none->id]],
    ));

    // A cheaper choice really is cheaper: 9.99 less 3.00.
    expect(priceCart($this->cart)->subtotal->toDecimalString())->toBe('6.99');
});

it('multiplies a quantity option by the number chosen', function (): void {
    $group = OptionGroup::factory()->forProduct($this->product)->create([
        'name' => 'Extra IPs',
        'key' => 'extra_ips',
        'type' => OptionType::Quantity->value,
        'min_quantity' => 0,
        'max_quantity' => 8,
    ]);

    $unit = Option::factory()->inGroup($group)->create(['label' => 'IP', 'value' => 'ip']);

    $unit->prices()->create([
        'organization_id' => $unit->organization_id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 200,
        'setup_minor' => 0,
    ]);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        options: [$group->id => ['quantity' => 3]],
    ));

    expect(priceCart($this->cart)->subtotal->toDecimalString())->toBe('15.99');
});

it('bills an addon on the cycle of the product it was bought with', function (): void {
    $addon = Addon::factory()->forProduct($this->product)->create(['name' => 'Dedicated IP']);

    foreach ([[BillingCycle::Monthly, 200], [BillingCycle::Annually, 2000]] as [$cycle, $minor]) {
        AddonPrice::factory()->create([
            'addon_id' => $addon->id,
            'organization_id' => $addon->organization_id,
            'billing_cycle' => $cycle->value,
            'currency_code' => 'EUR',
            'recurring_minor' => $minor,
            'setup_minor' => 0,
        ]);
    }

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Annually,
        addonIds: [$addon->id],
    ));

    $totals = priceCart($this->cart);

    expect($totals->lines)->toHaveCount(2)
        ->and($totals->subtotal->toDecimalString())->toBe('119.90')
        ->and($totals->recurringTotal->toDecimalString())->toBe('119.90');
});

it('refuses a cycle the product is not sold on', function (): void {
    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Triennially,
    ));
})->throws(PriceUnavailable::class);

it('refuses a retired product', function (): void {
    $retired = Product::factory()->retired()->create(['organization_id' => $this->provider->id]);
    ProductPrice::factory()->forProduct($retired)->currency('EUR')->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $retired->id,
        cycle: BillingCycle::Monthly,
    ));
})->throws(ProductNotOrderable::class);

it('refuses a sold-out product', function (): void {
    $soldOut = Product::factory()->soldOut()->create(['organization_id' => $this->provider->id]);
    ProductPrice::factory()->forProduct($soldOut)->currency('EUR')->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $soldOut->id,
        cycle: BillingCycle::Monthly,
    ));
})->throws(ProductNotOrderable::class);

it('refuses an option belonging to another product', function (): void {
    $other = Product::factory()->create(['organization_id' => $this->provider->id]);
    $group = OptionGroup::factory()->forProduct($other)->create();
    $option = Option::factory()->inGroup($group)->create();

    $mine = OptionGroup::factory()->forProduct($this->product)->create(['key' => 'panel']);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        options: [$mine->id => ['option_id' => $option->id]],
    ));
})->throws(InvalidCartItem::class);

it('refuses a required question left unanswered', function (): void {
    OptionGroup::factory()->forProduct($this->product)->create([
        'key' => 'panel',
        'is_required' => true,
    ]);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));
})->throws(InvalidCartItem::class);

it('refuses an addon that belongs to another product', function (): void {
    $other = Product::factory()->create(['organization_id' => $this->provider->id]);
    $addon = Addon::factory()->forProduct($other)->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        addonIds: [$addon->id],
    ));
})->throws(InvalidCartItem::class);

it('demands a domain for a product whose type needs one', function (): void {
    $hosting = Product::factory()->ofType(ProductType::SharedHosting)
        ->create(['organization_id' => $this->provider->id]);

    ProductPrice::factory()->forProduct($hosting)->currency('EUR')->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $hosting->id,
        cycle: BillingCycle::Monthly,
    ));
})->throws(InvalidCartItem::class);

it('takes an addon out with the line it hangs off', function (): void {
    $addon = Addon::factory()->forProduct($this->product)->create();

    AddonPrice::factory()->create([
        'addon_id' => $addon->id,
        'organization_id' => $addon->organization_id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 200,
        'setup_minor' => 0,
    ]);

    $item = app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        addonIds: [$addon->id],
    ));

    app(UpdateCartItem::class)->remove($item);

    expect($this->cart->allItems()->count())->toBe(0);
});

it('applies a percentage discount once, on the subtotal', function (): void {
    Promotion::factory()->forOrganization($this->provider)->code('SAVE10')->percentage('10')->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
        quantity: 3,
    ));

    $this->cart->update(['promotion_code' => 'SAVE10']);

    $totals = priceCart($this->cart);

    // 10% of 29.97 is 2.997, which rounds once to 3.00 — not three
    // roundings of 0.999 summing to 3.00 by luck.
    expect($totals->discount->toDecimalString())->toBe('3.00')
        ->and($totals->total->toDecimalString())->toBe('41.97');
});

it('allocates the discount across lines so the parts sum to the whole', function (): void {
    $second = Product::factory()->ofType(ProductType::Vps)->create(['organization_id' => $this->provider->id]);
    ProductPrice::factory()->forProduct($second)->currency('EUR')->amounts(1999)->create();

    Promotion::factory()->forOrganization($this->provider)->code('THIRD')->percentage('33.33')->create();

    foreach ([$this->product, $second] as $product) {
        app(AddToCart::class)->handle($this->cart->fresh() ?? $this->cart, new AddToCartRequest(
            productId: $product->id,
            cycle: BillingCycle::Monthly,
        ));
    }

    $this->cart->update(['promotion_code' => 'THIRD']);

    $totals = priceCart($this->cart);

    $allocated = array_reduce(
        $totals->lines,
        static fn (Money $carry, PricedLine $line): Money => $carry->plus($line->discount),
        Money::zero('EUR'),
    );

    expect($allocated->minorUnits)->toBe($totals->discount->minorUnits);
});

it('refuses a fixed discount in another currency', function (): void {
    Promotion::factory()->forOrganization($this->provider)->code('TRY50')->fixed(5000, 'TRY')->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'TRY50']);

    $totals = priceCart($this->cart);

    expect($totals->promotionRefusal)->toBe(PromotionRefusal::WrongCurrency)
        ->and($totals->discount->isZero())->toBeTrue();
});

it('never discounts more than the thing being discounted', function (): void {
    Promotion::factory()->forOrganization($this->provider)->code('BIG')->fixed(100000, 'EUR')->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'BIG']);

    $totals = priceCart($this->cart);

    expect($totals->discount->toDecimalString())->toBe('9.99')
        // The setup fee is outside an order-scoped discount's reach, so
        // there is still something to pay.
        ->and($totals->total->toDecimalString())->toBe('5.00');
});

it('reports an expired code rather than silently ignoring it', function (): void {
    Promotion::factory()->forOrganization($this->provider)->code('OLD')->expired()->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'OLD']);

    expect(priceCart($this->cart)->promotionRefusal)->toBe(PromotionRefusal::Expired);
});

it('reports a code that has not started yet', function (): void {
    Promotion::factory()->forOrganization($this->provider)->code('SOON')->notYetStarted()->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'SOON']);

    expect(priceCart($this->cart)->promotionRefusal)->toBe(PromotionRefusal::NotStarted);
});

it('reports a code that has been fully redeemed', function (): void {
    Promotion::factory()->forOrganization($this->provider)->code('GONE')->create([
        'usage_limit' => 5,
        'usage_count' => 5,
    ]);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'GONE']);

    expect(priceCart($this->cart)->promotionRefusal)->toBe(PromotionRefusal::UsageLimitReached);
});

it('reports an order that is too small for the code', function (): void {
    Promotion::factory()->forOrganization($this->provider)->code('BIGSPEND')->create([
        'minimum_subtotal_minor' => 50000,
        'currency_code' => 'EUR',
    ]);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'BIGSPEND']);

    expect(priceCart($this->cart)->promotionRefusal)->toBe(PromotionRefusal::MinimumNotMet);
});

it('reports a code that covers nothing in the cart', function (): void {
    $other = Product::factory()->create(['organization_id' => $this->provider->id]);

    $promotion = Promotion::factory()->forOrganization($this->provider)->code('OTHER')->create([
        'scope' => PromotionScope::Products->value,
    ]);
    $promotion->products()->attach($other->id);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'OTHER']);

    expect(priceCart($this->cart)->promotionRefusal)->toBe(PromotionRefusal::NoEligibleItems);
});

it('honours a code limited to one billing cycle', function (): void {
    Promotion::factory()->forOrganization($this->provider)->code('YEARLY')->percentage('20')->create([
        'billing_cycles' => [BillingCycle::Annually->value],
    ]);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'YEARLY']);

    expect(priceCart($this->cart)->promotionRefusal)->toBe(PromotionRefusal::NoEligibleItems);
});

it('discounts only the setup fee when that is the scope', function (): void {
    Promotion::factory()->forOrganization($this->provider)->code('NOSETUP')->percentage('100')->create([
        'scope' => PromotionScope::SetupFees->value,
    ]);

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'NOSETUP']);

    $totals = priceCart($this->cart);

    expect($totals->discount->toDecimalString())->toBe('5.00')
        ->and($totals->total->toDecimalString())->toBe('9.99');
});

it('charges no tax by default', function (): void {
    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $totals = priceCart($this->cart, new TaxableSupply(Money::zero('EUR'), countryCode: 'TR'));

    expect($totals->tax->isZero())->toBeTrue()
        ->and($totals->total->toDecimalString())->toBe('14.99');
});

it('charges the configured flat rate on the discounted amount', function (): void {
    config()->set('platform.tax.driver', 'flat');
    config()->set('platform.tax.flat.rate', '20');
    config()->set('platform.tax.flat.name', 'KDV');

    Promotion::factory()->forOrganization($this->provider)->code('TEN')->percentage('10')->create();

    app(AddToCart::class)->handle($this->cart, new AddToCartRequest(
        productId: $this->product->id,
        cycle: BillingCycle::Monthly,
    ));

    $this->cart->update(['promotion_code' => 'TEN']);

    $totals = priceCart($this->cart, new TaxableSupply(Money::zero('EUR'), countryCode: 'TR'));

    // 9.99 + 5.00 less a 1.00 discount is 13.99; 20% of that is 2.80.
    expect($totals->discount->toDecimalString())->toBe('1.00')
        ->and($totals->tax->total->toDecimalString())->toBe('2.80')
        ->and($totals->tax->components[0]->name ?? null)->toBe('KDV')
        ->and($totals->total->toDecimalString())->toBe('16.79');
});
