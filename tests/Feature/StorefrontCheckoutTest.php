<?php

declare(strict_types=1);

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\OptionType;
use App\Domain\Catalog\ProductType;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use App\Infrastructure\Tax\Models\TaxSetting;
use Database\Seeders\ProviderOrganizationSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    $this->provider = Organization::query()->withoutGlobalScope('organization')->whereNull('parent_id')->sole();

    CurrencyRecord::factory()->forOrganization($this->provider->id)->base()->code('EUR', 'Euro')->create();

    $group = ProductGroup::factory()->forOrganization($this->provider->id)->create(['name' => 'Shared Hosting']);

    $this->product = Product::factory()->inGroup($group)->ofType(ProductType::Vps)
        ->create(['name' => 'Starter Plan']);

    ProductPrice::factory()
        ->forProduct($this->product)
        ->cycle(BillingCycle::Monthly)
        ->currency('EUR')
        ->amounts(999, 500)
        ->create();
});

function addToCart(array $overrides = []): void
{
    test()->post('/cart', [
        'product_id' => test()->product->id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'quantity' => 1,
        ...$overrides,
    ]);
}

it('shows an empty cart without falling over', function (): void {
    $this->get('/cart')
        ->assertOk()
        ->assertSee(__('ordering.cart.empty'), false);
});

it('shows the configure screen with every cycle priced', function (): void {
    ProductPrice::factory()
        ->forProduct($this->product)
        ->cycle(BillingCycle::Annually)
        ->currency('EUR')
        ->amounts(9990)
        ->create();

    $this->get("/store/{$this->product->slug}/configure")
        ->assertOk()
        ->assertSee(__('catalog.cycles.monthly'), false)
        ->assertSee(__('catalog.cycles.annually'), false);
});

it('does not configure a retired product', function (): void {
    $this->product->update(['status' => 'retired']);

    $this->get("/store/{$this->product->slug}/configure")->assertNotFound();
});

it('adds a product to the cart and shows the totals', function (): void {
    addToCart();

    $this->get('/cart')
        ->assertOk()
        ->assertSee('Starter Plan')
        // 9.99 plus a 5.00 setup fee.
        ->assertSee('14.99', false);
});

it('keeps the cart across requests', function (): void {
    addToCart();

    expect(Cart::query()->withoutGlobalScope('organization')->count())->toBe(1);

    $this->get('/cart')->assertOk()->assertSee('Starter Plan');
});

it('refuses to configure a product that needs a domain without one', function (): void {
    $hosting = Product::factory()->ofType(ProductType::SharedHosting)
        ->create(['organization_id' => $this->provider->id]);

    ProductPrice::factory()->forProduct($hosting)->currency('EUR')->create();

    $this->post('/cart', [
        'product_id' => $hosting->id,
        'billing_cycle' => BillingCycle::Monthly->value,
    ])->assertStatus(422);
});

it('changes a line quantity', function (): void {
    addToCart();

    $item = Cart::query()->withoutGlobalScope('organization')->sole()->allItems()->sole();

    $this->put("/cart/items/{$item->id}", ['quantity' => 3])->assertRedirect();

    expect($item->fresh()?->quantity)->toBe(3);
});

it('removes a line', function (): void {
    addToCart();

    $item = Cart::query()->withoutGlobalScope('organization')->sole()->allItems()->sole();

    $this->delete("/cart/items/{$item->id}")->assertRedirect();

    expect(Cart::query()->withoutGlobalScope('organization')->sole()->allItems()->count())->toBe(0);
});

it('does not let one visitor edit another visitors cart', function (): void {
    addToCart();

    $item = Cart::query()->withoutGlobalScope('organization')->sole()->allItems()->sole();

    // A fresh session is a different browser.
    $this->flushSession();

    $this->delete("/cart/items/{$item->id}")->assertNotFound();
});

it('applies a promotion code', function (): void {
    Promotion::factory()->forOrganization($this->provider->id)->code('SAVE10')->percentage('10')->create();

    addToCart();

    $this->post('/cart/code', ['code' => 'save10'])->assertRedirect();

    $this->get('/cart')->assertOk()->assertSee('SAVE10', false);
});

it('says why a code did not apply', function (): void {
    Promotion::factory()->forOrganization($this->provider->id)->code('OLD')->expired()->create();

    addToCart();

    $this->post('/cart/code', ['code' => 'OLD'])
        ->assertSessionHasErrors('code');
});

it('sends an empty cart away from checkout', function (): void {
    $this->get('/checkout')->assertRedirect('/cart');
});

it('places an order for a new visitor and creates their account', function (): void {
    addToCart();

    $response = $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'country_code' => 'TR',
        'terms' => '1',
        'expected_total' => 1499,
    ]);

    $order = Order::query()->withoutGlobalScope('organization')->sole();

    $response->assertRedirect("/orders/{$order->number}");

    $contact = Contact::query()->withoutGlobalScope('organization')->where('email', 'ayse@example.com')->sole();

    expect($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->contact_id)->toBe($contact->id)
        ->and($order->terms_accepted_at)->not->toBeNull()
        // No password travels through a checkout form. The account holds a
        // random one nobody has ever seen, so it is reachable only through
        // the reset flow — which also proves the address.
        ->and($contact->portal_access)->toBeTrue()
        ->and($contact->password)->not->toBeNull()
        ->and(Hash::check('', (string) $contact->password))->toBeFalse();
});

/**
 * A guest buying as a business has to be able to say so.
 *
 * The form carried a company name and no tax id at all, which meant a seller who
 * turned on "ask a business for a tax id" would have refused every business
 * checkout with an error against a field that was not on the page — and reverse
 * charge could never be claimed at checkout either, because there was nowhere to
 * put the number it needs.
 */
it('asks a guest for a tax id, in the seller\'s own words', function (): void {
    addToCart();

    TaxSetting::factory()
        ->forOrganization($this->provider->id)
        ->create(['tax_id_label' => 'Vergi Numarası', 'require_tax_id_for_business' => true]);

    $this->get('/checkout')
        ->assertOk()
        ->assertSee('name="tax_id"', false)
        ->assertSee('Vergi Numarası');

    // A business without one is refused, and told which field.
    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'company' => 'Acme A.Ş.',
        'country_code' => 'TR',
        'terms' => '1',
        'expected_total' => 1499,
    ])->assertSessionHasErrors('tax_id');

    expect(Order::query()->withoutGlobalScope('organization')->count())->toBe(0);

    // An individual is asked for nothing.
    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'country_code' => 'TR',
        'terms' => '1',
        'expected_total' => 1499,
    ])->assertSessionHasNoErrors();

    expect(Order::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

/**
 * The confirmation page has always said "we have emailed a confirmation".
 * Nothing sent one, because there was no event for anybody to subscribe
 * to — so the one sentence a customer reads after paying was untrue.
 */
it('emails the confirmation the checkout page promises', function (): void {
    addToCart();

    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'country_code' => 'TR',
        'terms' => '1',
        'expected_total' => 1499,
    ])->assertRedirect();

    $delivery = NotificationDelivery::query()
        ->withoutGlobalScope('organization')
        ->where('event', NotificationEvent::OrderPlaced->value)
        ->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->recipient_address)->toBe('ayse@example.com');
});

it('refuses an order with the terms unticked', function (): void {
    addToCart();

    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'expected_total' => 1499,
    ])->assertSessionHasErrors('terms');
});

it('refuses an address that already has an account', function (): void {
    addToCart();

    Contact::factory()->create(['email' => 'taken@example.com']);

    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'taken@example.com',
        'terms' => '1',
        'expected_total' => 1499,
    ])->assertSessionHasErrors('email');
});

it('stops the order when the price moved while the customer was reading', function (): void {
    addToCart();

    $this->product->prices()->where('billing_cycle', BillingCycle::Monthly->value)
        ->update(['recurring_minor' => 1299]);

    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'terms' => '1',
        'expected_total' => 1499,
    ])->assertStatus(412);

    expect(Order::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('carries the chosen options onto the order', function (): void {
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

    addToCart(['options' => [$group->id => ['option_id' => $option->id]]]);

    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'terms' => '1',
        'expected_total' => 1999,
    ])->assertSessionHasNoErrors();

    $order = Order::query()->withoutGlobalScope('organization')->sole();
    $line = $order->allItems()->first();

    expect($order->total->toDecimalString())->toBe('19.99')
        ->and($line?->options()->first()?->label)->toBe('cPanel');
});

it('shows the confirmation to the browser that placed the order', function (): void {
    addToCart();

    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'terms' => '1',
        'expected_total' => 1499,
    ]);

    $order = Order::query()->withoutGlobalScope('organization')->sole();

    $this->get("/orders/{$order->number}")
        ->assertOk()
        ->assertSee($order->number);
});

it('hides an order confirmation from a stranger', function (): void {
    $order = Order::factory()->forCustomer(
        Customer::factory()->forOrganization($this->provider)->create()
    )->create(['number' => 'ORD-000999']);

    $this->get("/orders/{$order->number}")->assertNotFound();
});

it('shows the invoice on the confirmation page', function (): void {
    addToCart();

    $this->followingRedirects()
        ->post('/checkout', [
            'first_name' => 'Ayse',
            'last_name' => 'Yilmaz',
            'email' => 'ayse@example.com',
            'country_code' => 'TR',
            'terms' => '1',
            'expected_total' => 1499,
        ])
        ->assertOk()
        ->assertSee(Invoice::query()->withoutGlobalScope('organization')->sole()->number)
        ->assertSee(__('billing.payments.pay_now'), false);

    $order = Order::query()->withoutGlobalScope('organization')->sole();
    $invoice = Invoice::query()->withoutGlobalScope('organization')->sole();

    expect($invoice->order_id)->toBe($order->id)
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid)
        ->and($invoice->total->minorUnits)->toBe($order->total->minorUnits)
        // Issued, so the bill-to party is copied onto it and will not move
        // if the customer edits their details tomorrow.
        ->and($invoice->bill_to_email)->toBe('ayse@example.com')
        ->and($invoice->items()->count())->toBe($order->allItems()->count());
});

it('raises no invoice for an order held for fraud review', function (): void {
    // Every first order is flagged, so this one is held rather than billed.
    config()->set('platform.risk.rules.flag_first_order', true);
    config()->set('platform.risk.rules.first_order_weight', 5);
    config()->set('platform.risk.rules.review_score', 1);

    addToCart();

    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'country_code' => 'TR',
        'terms' => '1',
        'expected_total' => 1499,
    ]);

    $order = Order::query()->withoutGlobalScope('organization')->sole();

    expect($order->status)->toBe(OrderStatus::FraudReview)
        ->and(Invoice::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('lets the browser that checked out open the invoice it just raised', function (): void {
    addToCart();

    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'country_code' => 'TR',
        'terms' => '1',
        'expected_total' => 1499,
    ]);

    $invoice = Invoice::query()->withoutGlobalScope('organization')->sole();

    // The account has no password until the reset mail arrives, so the
    // session is the only identity this customer has for now.
    $this->get("/invoices/{$invoice->number}")
        ->assertOk()
        ->assertSee($invoice->number);
});

it('does not carry that permission into a different browser', function (): void {
    addToCart();

    $this->post('/checkout', [
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.com',
        'country_code' => 'TR',
        'terms' => '1',
        'expected_total' => 1499,
    ]);

    $invoice = Invoice::query()->withoutGlobalScope('organization')->sole();

    $this->flushSession();

    $this->get("/invoices/{$invoice->number}")->assertNotFound();
});
