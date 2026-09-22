<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\TransactionKind;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Ordering\Models\Order;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create();

    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create([
        'first_name' => 'Ines',
    ]);
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();

    $this->member = Contact::factory()->forCustomer($this->customer)->create();
    $this->member->assignRole(SystemRole::PortalMember);
    $this->member = $this->member->fresh();
});

it('greets the contact and shows nothing it has no data for', function (): void {
    $this->actingAs($this->owner, 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Dashboard')
            ->where('name', 'Ines')
            ->has('unpaid', 0)
            ->has('orders', 0)
            // A zero balance is not news; the panel is absent rather than
            // rendered empty.
            ->where('credit', null));
});

it('puts what is owed soonest at the top', function (): void {
    $later = Invoice::factory()->forCustomer($this->customer)->status(InvoiceStatus::Unpaid)
        ->create(['due_on' => CarbonImmutable::now()->addDays(20)->toDateString()]);
    $sooner = Invoice::factory()->forCustomer($this->customer)->status(InvoiceStatus::Unpaid)
        ->create(['due_on' => CarbonImmutable::now()->addDays(2)->toDateString()]);

    $this->actingAs($this->owner, 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('unpaid', 2)
            ->where('unpaid.0.number', $sooner->number)
            ->where('unpaid.1.number', $later->number));
});

it('shows a credit balance once there is one', function (): void {
    Transaction::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'kind' => TransactionKind::CreditAdded->value,
        'amount_minor' => 2500,
        'credit_balance_minor' => 2500,
        'currency_code' => $this->customer->currency_code,
    ]);

    $this->actingAs($this->owner, 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('credit.balance', '€25.00'));
});

it('shows the recent orders', function (): void {
    $order = Order::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::AwaitingPayment->value,
    ]);

    $this->actingAs($this->owner, 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('orders', 1)
            ->where('orders.0.number', $order->number));
});

it('never shows another customers figures', function (): void {
    $stranger = Customer::factory()->create();
    Invoice::factory()->forCustomer($stranger)->status(InvoiceStatus::Unpaid)->create();
    Order::factory()->create([
        'organization_id' => $stranger->organization_id,
        'customer_id' => $stranger->id,
        'status' => OrderStatus::AwaitingPayment->value,
    ]);

    $this->actingAs($this->owner, 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('unpaid', 0)
            ->has('orders', 0));
});

it('does not put a total owed in front of a contact who cannot see billing', function (): void {
    Invoice::factory()->forCustomer($this->customer)->status(InvoiceStatus::Unpaid)->create();

    $this->actingAs($this->member, 'client')
        ->get('/client')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.billing', false)
            ->has('unpaid', 0)
            ->where('credit', null));
});
