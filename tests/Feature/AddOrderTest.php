<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use Database\Factories\ProductPriceFactory;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * An order taken over the phone.
 *
 * The rule under test everywhere here: **it goes through the cart**. The
 * obvious shortcut — writing the order and its lines straight from the
 * form — would be a second place that prices a sale, and the two would
 * disagree the first time somebody changed a tax rule or a promotion.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->customer = Customer::factory()->create(['currency_code' => 'EUR']);
    Contact::factory()->forCustomer($this->customer)->primary()->create();

    // The factory's default needs a hostname; these tests are about
    // pricing, not about provisioning.
    $this->product = Product::factory()->create(['requires_domain' => false]);

    ProductPriceFactory::new()
        ->forProduct($this->product)
        ->cycle(BillingCycle::Monthly)
        ->currency('EUR')
        ->amounts(2500, 1000)
        ->create();
});

it('offers only what is sold in the client currency', function (): void {
    $other = Product::factory()->create();

    ProductPriceFactory::new()
        ->forProduct($other)
        ->cycle(BillingCycle::Monthly)
        ->currency('USD')
        ->amounts(999)
        ->create();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/orders/add?customer='.$this->customer->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Orders/Create')
            ->where('currency', 'EUR')
            ->has('products', 1)
            ->where('products.0.id', $this->product->id));
});

it('places an order through the cart and prices it there', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 2,
            ]],
        ])
        ->assertRedirect();

    $order = Order::query()->orderByDesc('id')->firstOrFail();

    // 2 x (2500 recurring + 1000 setup).
    expect($order->subtotal->minorUnits)->toBe(5000)
        ->and($order->setup->minorUnits)->toBe(2000)
        ->and($order->items()->count())->toBe(1)
        ->and($order->status)->toBe(OrderStatus::AwaitingPayment);
});

it('writes the price an operator agreed onto the line', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'price_override' => '15.00',
            ]],
        ])
        ->assertRedirect();

    $line = OrderItem::query()->orderByDesc('id')->firstOrFail();

    // The agreed number is on the document, like every other amount, so a
    // later price change cannot move it (ADR 0021).
    expect($line->unit_recurring->minorUnits)->toBe(1500)
        ->and($line->price_override_minor)->toBe(1500);
});

/**
 * Null is not zero. No override means "whatever the catalogue says"; a
 * zero means somebody agreed to give it away.
 */
it('tells an override of nothing apart from an override of zero', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
                'price_override' => '0',
            ]],
        ])
        ->assertRedirect();

    $line = OrderItem::query()->orderByDesc('id')->firstOrFail();

    expect($line->price_override_minor)->toBe(0)
        ->and($line->unit_recurring->minorUnits)->toBe(0)
        // The setup fee is untouched: agreeing a monthly rate is not
        // agreeing to waive setup.
        ->and($line->unit_setup->minorUnits)->toBe(1000);
});

/**
 * The status is not the desk's decision. `PlaceOrder` works it out from
 * the risk assessment and the total, exactly as it does for a storefront
 * order, and a screen that overrode it would be a second opinion about
 * fraud. What the switches decide is who hears about it.
 */
it('tells the customer only when the desk asked for it', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
            ]],
            'confirm' => false,
        ])
        ->assertRedirect();

    expect(NotificationDelivery::query()
        ->where('event', NotificationEvent::OrderPlaced->value)
        ->count())->toBe(0);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
            ]],
            'confirm' => true,
        ])
        ->assertRedirect();

    expect(NotificationDelivery::query()
        ->where('event', NotificationEvent::OrderPlaced->value)
        ->count())->toBeGreaterThan(0);
});

it('raises the invoice when asked and leaves it alone when not', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
            ]],
            'generate_invoice' => true,
        ])
        ->assertRedirect();

    $billed = Order::query()->orderByDesc('id')->firstOrFail();

    expect($billed->invoices()->count())->toBe(1);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
            ]],
        ])
        ->assertRedirect();

    expect(Order::query()->orderByDesc('id')->firstOrFail()->invoices()->count())->toBe(0);
});

/**
 * The customer never clicked anything, so the field a dispute turns on
 * stays empty. Who took the order is in the audit trail instead.
 */
it('does not claim the customer accepted terms they never saw', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
            ]],
        ])
        ->assertRedirect();

    expect(Order::query()->orderByDesc('id')->firstOrFail()->terms_accepted_at)->toBeNull();
});

it('leaves no cart behind, whether it worked or not', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'monthly',
            ]],
        ])
        ->assertRedirect();

    expect(Cart::query()->count())->toBe(0);

    // A cycle this product is not sold on: the cart refuses the line, and
    // the cart it was building has to go with it.
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'billing_cycle' => 'triennially',
            ]],
        ]);

    expect(Cart::query()->count())->toBe(0)
        ->and(Order::query()->count())->toBe(1);
});

it('refuses an order with nothing on it', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/orders', ['customer_id' => $this->customer->id, 'lines' => []])
        ->assertSessionHasErrors();

    expect(Order::query()->count())->toBe(0);
});

it('keeps somebody without the permission out', function (): void {
    $support = StaffUser::factory()->create();
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->get('/admin/orders/add')
        ->assertForbidden();
});
