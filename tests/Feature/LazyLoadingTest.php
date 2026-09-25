<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Billing\AddTransaction;
use App\Application\Billing\AddTransactionRequest;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Ordering\LineKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Provisioning\Models\Service;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Database\Eloquent\Model;

/**
 * Strict mode only reports a lazy load when the query that produced the
 * model returned **more than one row** — Laravel's N+1 heuristic, in
 * `Builder::hydrate()`. A screen that is correct with one record and throws
 * with two therefore passes every test written against a single fixture,
 * and fails the first time an operator opens it against real data.
 *
 * So every test here creates at least two of everything, on purpose. That
 * is the whole point of the file and the reason it is separate: a fixture
 * trimmed to one row later would make these tests green and useless.
 *
 * The bug they were written for: `Customer::displayName()` falls back to
 * the primary contact when there is no company or legal name, so listing
 * customers by name lazily loads a relation on every row — and a customer
 * created at checkout by an individual has no company name.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

it('makes strict mode report lazy loads in this suite', function (): void {
    // If this ever fails, every other test in this file is meaningless.
    expect(Model::preventsLazyLoading())->toBeTrue();
});

/**
 * An individual, which is the case that breaks: no company name, so the
 * display name comes from the contact.
 */
function individuals(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $customer = Customer::factory()->create([
            'company_name' => null,
            'legal_name' => null,
        ]);

        Contact::factory()->forCustomer($customer)->primary()->create();
    }
}

it('lists orders for customers who have no company name', function (): void {
    individuals(2);

    foreach (Customer::query()->get() as $customer) {
        Order::factory()->create([
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/orders')
        ->assertOk();
});

it('lists invoices for customers who have no company name', function (): void {
    individuals(2);

    foreach (Customer::query()->get() as $customer) {
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/invoices')
        ->assertOk();
});

it('lists services and domains for customers who have no company name', function (): void {
    individuals(2);

    foreach (Customer::query()->get() as $customer) {
        Service::factory()->create([
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);

        Domain::factory()->create([
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    $this->actingAs($this->admin, 'staff')->get('/admin/services')->assertOk();
    $this->actingAs($this->admin, 'staff')->get('/admin/domains')->assertOk();
});

it('shows an order whose line has two addons under it', function (): void {
    $customer = Customer::factory()->create(['company_name' => null, 'legal_name' => null]);
    Contact::factory()->forCustomer($customer)->primary()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
    ]);

    $line = OrderItem::factory()->create([
        'order_id' => $order->id,
        'organization_id' => $order->organization_id,
        'kind' => LineKind::Product->value,
        'parent_id' => null,
    ]);

    // Two, so the children are hydrated with the strict flag set. With one
    // addon the presenter's blind recursion into a third level goes
    // unreported, which is exactly how it survived until now.
    for ($i = 0; $i < 2; $i++) {
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'organization_id' => $order->organization_id,
            'kind' => LineKind::Addon->value,
            'parent_id' => $line->id,
        ]);
    }

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/orders/'.$order->id)
        ->assertOk();
});

it('lists customers for customers who have no company name', function (): void {
    individuals(2);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers')
        ->assertOk();
});

/**
 * The deepest fallback: no company name, no legal name and nobody on the
 * record, so `displayName()` reaches for the organization. Two of them,
 * because one would not be reported.
 */
it('lists customers who have no company name and nobody on the record', function (): void {
    Customer::factory()->count(2)->create(['company_name' => null, 'legal_name' => null]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers')
        ->assertOk();
});

it('lists customer users for customers who have no company name', function (): void {
    individuals(2);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customer-users')
        ->assertOk();
});

/**
 * The manage-users list shows the customer each person belongs to, and a
 * second person on the same customer is the ordinary case — a company with
 * an owner and an accountant. Both rows read the customer's display name.
 */
it('lists two users of the same customer without a company name', function (): void {
    $customer = Customer::factory()->create(['company_name' => null, 'legal_name' => null]);

    Contact::factory()->forCustomer($customer)->primary()->create();
    Contact::factory()->forCustomer($customer)->create();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customer-users')
        ->assertOk();
});

/**
 * The ledger lists a client name on every row, and a client who paid by
 * bank transfer is exactly the individual with no company name.
 */
it('lists transactions for customers who have no company name', function (): void {
    for ($i = 0; $i < 2; $i++) {
        $customer = Customer::factory()->create(['company_name' => null, 'legal_name' => null]);

        Contact::factory()->forCustomer($customer)->primary()->create();

        Transaction::factory()->forCustomer($customer)->create();
    }

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/transactions')
        ->assertOk();
});

/**
 * Splitting one transfer across two invoices is the case that found the
 * lazy load in `RecordPayment::attach()`: it reads `$invoice->customer`,
 * and one invoice would never have reported it.
 */
it('pays two invoices from one transfer without lazy loading either', function (): void {
    $customer = Customer::factory()->create([
        'company_name' => null,
        'legal_name' => null,
        'currency_code' => 'EUR',
    ]);

    Contact::factory()->forCustomer($customer)->primary()->create();

    $invoices = collect(range(1, 2))->map(fn (): Invoice => Invoice::factory()
        ->forCustomer($customer)
        ->create([
            'currency_code' => 'EUR',
            'subtotal_minor' => 1000,
            'total_minor' => 1000,
            'status' => InvoiceStatus::Unpaid->value,
        ]));

    app(AddTransaction::class)->handle($customer, new AddTransactionRequest(
        amountIn: Money::ofMinor(2000, 'EUR'),
        amountOut: Money::zero('EUR'),
        occurredAt: CarbonImmutable::now(),
        invoiceIds: $invoices->pluck('number')->all(),
    ), $this->admin);

    expect($invoices->map->fresh()->pluck('status')->all())
        ->each->toBe(InvoiceStatus::Paid);
});

/**
 * The portal's own landing page, with two of everything on it.
 *
 * Strict mode only reports a lazy load when the query returned more than one
 * row, so a dashboard that is correct with one service and throws with two
 * passes every test written against a single fixture.
 */
it('shows a customer two services, two domains, two invoices and two orders', function (): void {
    $customer = Customer::factory()->create(['company_name' => null, 'legal_name' => null]);

    $contact = Contact::factory()->forCustomer($customer)->primary()->create();
    $contact->assignRole(SystemRole::AccountOwner);

    foreach (range(1, 2) as $index) {
        Service::factory()->forCustomer($customer)->active()->create(['name' => 'Plan '.$index]);
        Domain::factory()->forCustomer($customer)->active()->create(['name' => 'site'.$index.'.test']);
        Invoice::factory()->forCustomer($customer)->status(InvoiceStatus::Unpaid)->create();
        Order::factory()->forCustomer($customer)->create();
    }

    $this->actingAs($contact->fresh(), 'client')->get('/client')->assertOk();
});
