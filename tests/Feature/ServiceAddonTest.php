<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\RecordedRun;
use App\Application\Automation\Runs\GenerateRenewalInvoices;
use App\Application\Provisioning\CreateServicesForOrder;
use App\Application\Provisioning\TransitionService;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Ordering\LineKind;
use App\Domain\Provisioning\AddonStatus;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceAddon;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * An addon is not a service and not just an order line (ADR 0035): it has
 * its own price, its own cycle, its own renewal date and its own status,
 * and it follows the service it hangs off.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->operator = StaffUser::factory()->create();
    $this->operator->assignRole(SystemRole::Administrator);
    $this->operator = $this->operator->fresh();

    $this->runner = app(RecordedRun::class);
});

function orderWithAddon(?Customer $customer = null, int $addonMinor = 500): Order
{
    $customer ??= Customer::factory()->create();
    $product = Product::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'currency_code' => 'EUR',
    ]);

    $line = OrderItem::factory()->create([
        'order_id' => $order->id,
        'organization_id' => $order->organization_id,
        'kind' => LineKind::Product->value,
        'product_id' => $product->id,
        'parent_id' => null,
        'name' => 'Starter Hosting',
        'billing_cycle' => BillingCycle::Monthly->value,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'organization_id' => $order->organization_id,
        'kind' => LineKind::Addon->value,
        'parent_id' => $line->id,
        'name' => 'Extra backup space',
        'billing_cycle' => BillingCycle::Monthly->value,
        'quantity' => 2,
        'line_recurring_minor' => $addonMinor,
        'line_setup_minor' => 0,
    ]);

    return $order->refresh();
}

it('turns an addon line into something that renews', function (): void {
    $order = orderWithAddon();

    app(CreateServicesForOrder::class)->handle($order);

    $addon = ServiceAddon::query()->firstOrFail();
    $service = Service::query()->firstOrFail();

    expect($addon->service_id)->toBe($service->id)
        ->and($addon->name)->toBe('Extra backup space')
        ->and($addon->quantity)->toBe(2)
        ->and($addon->recurring->minorUnits)->toBe(500)
        ->and($addon->currency_code)->toBe('EUR')
        ->and($addon->status)->toBe(AddonStatus::Pending)
        // The whole point: a date the renewal sweep can see.
        ->and($addon->next_due_on?->toDateString())->toBe(now()->addMonth()->toDateString());
});

it('creates the addon once however often the order is fulfilled', function (): void {
    $order = orderWithAddon();

    app(CreateServicesForOrder::class)->handle($order);
    app(CreateServicesForOrder::class)->handle($order->refresh());

    // A webhook replayed, or an operator recording a transfer that a
    // webhook then confirms.
    expect(ServiceAddon::query()->count())->toBe(1)
        ->and(Service::query()->count())->toBe(1);
});

/**
 * The failure this guards: a first run that created the service and then
 * crashed leaves an account with no addons, and nothing would ever add
 * them because the service already exists.
 */
it('writes the addons even when the service was created by an earlier run', function (): void {
    $order = orderWithAddon();

    app(CreateServicesForOrder::class)->handle($order);

    ServiceAddon::query()->delete();

    app(CreateServicesForOrder::class)->handle($order->refresh());

    expect(ServiceAddon::query()->count())->toBe(1);
});

it('takes the addons with the service into suspension and back', function (): void {
    $service = Service::factory()->active()->create();
    $addon = ServiceAddon::factory()->on($service)->active()->create();

    app(TransitionService::class)->handle($service, ServiceStatus::Suspended);

    expect($addon->fresh()?->status)->toBe(AddonStatus::Suspended);

    app(TransitionService::class)->handle($service->fresh(), ServiceStatus::Active);

    expect($addon->fresh()?->status)->toBe(AddonStatus::Active);
});

it('takes the addons with the service when it is terminated', function (): void {
    $service = Service::factory()->active()->create();
    $addon = ServiceAddon::factory()->on($service)->active()->create();

    app(TransitionService::class)->handle($service, ServiceStatus::Terminated);

    $addon = $addon->fresh();

    expect($addon?->status)->toBe(AddonStatus::Terminated)
        ->and($addon?->terminated_at)->not->toBeNull();
});

/**
 * "We turned the account back on" does not undo "they asked to stop paying
 * for this".
 */
it('leaves an addon the customer dropped where it is', function (): void {
    $service = Service::factory()->active()->create();

    $dropped = ServiceAddon::factory()->on($service)->status(AddonStatus::Terminated)->create();
    $cancelling = ServiceAddon::factory()->on($service)->status(AddonStatus::CancelPending)->create();

    app(TransitionService::class)->handle($service, ServiceStatus::Suspended);
    app(TransitionService::class)->handle($service->fresh(), ServiceStatus::Active);

    expect($dropped->fresh()?->status)->toBe(AddonStatus::Terminated)
        ->and($cancelling->fresh()?->status)->toBe(AddonStatus::CancelPending);
});

it('invoices an addon that is due, on the same invoice as its service', function (): void {
    $customer = Customer::factory()->create();

    $service = Service::factory()->forCustomer($customer)->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 1500,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    ServiceAddon::factory()->on($service)->active()->create([
        'currency_code' => 'EUR',
        'recurring_minor' => 500,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    $invoices = Invoice::query()->withoutGlobalScope('organization')
        ->where('customer_id', $customer->id)
        ->get();

    // One document, because that is what a bank transfer can pay.
    expect($invoices)->toHaveCount(1)
        ->and($invoices->first()?->total->minorUnits)->toBe(2000);

    $line = InvoiceItem::query()->withoutGlobalScope('organization')
        ->where('subject_type', ServiceAddon::class)
        ->firstOrFail();

    expect($line->description)->toBe('Extra backup space');
});

it('does not invoice the same addon term twice', function (): void {
    $service = Service::factory()->active()->create([
        'billing_cycle' => BillingCycle::Monthly->value,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    ServiceAddon::factory()->on($service)->active()->create([
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));
    $second = $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    expect($second->changed)->toBe(0)
        ->and($second->examined)->toBe(0);
});

/**
 * A cancelled addon is still running until the term ends. Invoicing it
 * would charge for a term the customer has said they do not want.
 */
it('leaves a cancelling addon out of the renewal sweep', function (): void {
    $service = Service::factory()->active()->create([
        'billing_cycle' => BillingCycle::Monthly->value,
        'next_due_on' => now()->addYear()->toDateString(),
    ]);

    ServiceAddon::factory()->on($service)->status(AddonStatus::CancelPending)->create([
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    $record = $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    expect($record->examined)->toBe(0)
        ->and($record->changed)->toBe(0);
});

it('lists the addons with the filters the services screen has', function (): void {
    $group = ServerGroup::factory()->create();
    $server = Server::factory()->inGroup($group)->create(['name' => 'web-09']);

    $customer = Customer::factory()->create(['company_name' => null, 'legal_name' => null]);
    Contact::factory()->forCustomer($customer)->primary()->create([
        'first_name' => 'Zeynep',
        'last_name' => 'Kaya',
    ]);

    $service = Service::factory()->forCustomer($customer)->on($server)->active()->create();
    ServiceAddon::factory()->on($service)->active()->create(['name' => 'Extra backup space']);

    // A second one, so strict mode reports any lazy load the row makes.
    $other = Service::factory()->active()->create();
    ServiceAddon::factory()->on($other)->active()->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services/addons')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Services/Addons')
            ->has('addons.data', 2)
            ->has('schema.statuses', 5));

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services/addons?server='.$server->id)
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('addons.data', 1)
            ->where('addons.data.0.name', 'Extra backup space')
            ->where('addons.data.0.detail.server', 'web-09'));

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services/addons?client=Zeynep+Kaya')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('addons.data', 1));
});

it('hides the addons of a closed account until somebody asks', function (): void {
    $closed = Customer::factory()->create(['status' => CustomerStatus::Closed->value]);
    $closedService = Service::factory()->forCustomer($closed)->create();
    ServiceAddon::factory()->on($closedService)->active()->create();

    $trading = Service::factory()->create();
    ServiceAddon::factory()->on($trading)->active()->create();

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services/addons')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('addons.data', 1));

    $this->actingAs($this->operator, 'staff')
        ->get('/admin/services/addons?inactive=1')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('addons.data', 2));
});

it('refuses the addons screen to staff without the permission', function (): void {
    $support = StaffUser::factory()->create();
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->get('/admin/services/addons')
        ->assertForbidden();
});
