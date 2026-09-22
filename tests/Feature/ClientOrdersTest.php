<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create();

    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();

    $this->member = Contact::factory()->forCustomer($this->customer)->create();
    $this->member->assignRole(SystemRole::PortalMember);
    $this->member = $this->member->fresh();

    $this->order = Order::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::AwaitingPayment->value,
    ]);

    OrderItem::factory()->create([
        'organization_id' => $this->order->organization_id,
        'order_id' => $this->order->id,
        'name' => 'Starter Plan',
    ]);
});

it('lists the orders this customer has placed', function (): void {
    $this->actingAs($this->owner, 'client')
        ->get('/client/orders')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Orders/Index')
            ->has('orders.data', 1)
            ->where('orders.data.0.number', $this->order->number));
});

it('shows the copy of the catalog the order froze', function (): void {
    // The product is renamed afterwards; the order still says what was
    // bought (ADR 0021).
    $this->actingAs($this->owner, 'client')
        ->get("/client/orders/{$this->order->number}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Orders/Show')
            ->where('order.items.0.name', 'Starter Plan'));
});

it('links the order to the invoice raised for it', function (): void {
    $invoice = Invoice::factory()
        ->forCustomer($this->customer)
        ->status(InvoiceStatus::Unpaid)
        ->create(['order_id' => $this->order->id]);

    $this->actingAs($this->owner, 'client')
        ->get("/client/orders/{$this->order->number}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('invoice.number', $invoice->number)
            ->where('invoice.isOwed', true));
});

it('never tells a customer why their order was held', function (): void {
    $this->order->forceFill([
        'status' => OrderStatus::FraudReview->value,
        'risk_decision' => 'review',
        'risk_score' => 7,
        'risk_reasons' => ['velocity', 'country_mismatch'],
    ])->save();

    // Telling a customer which rule fired is telling whoever is probing
    // the rules.
    $this->actingAs($this->owner, 'client')
        ->get("/client/orders/{$this->order->number}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->missing('order.riskDecision')
            ->missing('order.riskReasons'))
        ->assertDontSee('country_mismatch');
});

it('lets a portal member see orders but not billing', function (): void {
    $this->actingAs($this->member, 'client')->get('/client/orders')->assertOk();
    $this->actingAs($this->member, 'client')->get('/client/billing')->assertForbidden();
});

it('hides another customers order', function (): void {
    $stranger = Order::factory()->create(['status' => OrderStatus::AwaitingPayment->value]);

    $this->actingAs($this->owner, 'client')
        ->get("/client/orders/{$stranger->number}")
        ->assertNotFound();
});

it('does not show a draft order', function (): void {
    $draft = Order::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft->value,
    ]);

    $this->actingAs($this->owner, 'client')
        ->get("/client/orders/{$draft->number}")
        ->assertNotFound();
});
