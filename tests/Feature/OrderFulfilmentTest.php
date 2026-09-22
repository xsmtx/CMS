<?php

declare(strict_types=1);

use App\Application\Billing\RecordPayment;
use App\Application\Billing\RecordPaymentRequest;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Provisioning\AutoSetup;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Provisioning\Jobs\ProvisionService;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\ModuleRegistry;
use Database\Seeders\ProviderOrganizationSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeProvisioningModule;

/**
 * The chain the whole platform has been building towards: money arrives and
 * something gets set up.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->module = new FakeProvisioningModule;
    $registry = new ModuleRegistry;
    $registry->register($this->module);
    $this->app->instance(ModuleRegistry::class, $registry);

    $this->group = ServerGroup::factory()->create();
    Server::factory()->inGroup($this->group)->create(['module' => 'fake']);

    $this->customer = Customer::factory()->create();

    $this->product = Product::factory()->create([
        'provisioning_module' => 'fake',
        'server_group_id' => $this->group->id,
        'provisioning_package' => 'starter',
        'auto_setup' => AutoSetup::OnPayment->value,
    ]);

    $this->order = Order::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::AwaitingPayment->value,
        'currency_code' => 'EUR',
        'total_minor' => 1499,
    ]);

    OrderItem::factory()->create([
        'organization_id' => $this->order->organization_id,
        'order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'name' => 'Starter Plan',
        'line_recurring_minor' => 1499,
    ]);

    $this->invoice = Invoice::factory()
        ->forCustomer($this->customer)
        ->status(InvoiceStatus::Unpaid)
        ->totalling(1499)
        ->create(['order_id' => $this->order->id]);

    InvoiceItem::factory()->forInvoice($this->invoice)->create();
});

it('turns a paid invoice into a service and queues the setup', function (): void {
    Queue::fake();

    app(RecordPayment::class)->handle($this->invoice, new RecordPaymentRequest(Money::ofMinor(1499, 'EUR')));

    expect($this->order->fresh()?->status)->toBe(OrderStatus::Paid);

    $service = Service::query()->withoutGlobalScope('organization')->sole();

    expect($service->name)->toBe('Starter Plan')
        ->and($service->status)->toBe(ServiceStatus::Pending)
        ->and($service->module)->toBe('fake');

    // Queued, never run inside the request: the fastest control panel is
    // slower than a customer's patience, and a webhook that times out
    // waiting for one gets redelivered.
    Queue::assertPushed(
        ProvisionService::class,
        fn (ProvisionService $job): bool => $job->serviceId === $service->id,
    );
});

it('runs the queued job through to an active service', function (): void {
    app(RecordPayment::class)->handle($this->invoice, new RecordPaymentRequest(Money::ofMinor(1499, 'EUR')));

    $service = Service::query()->withoutGlobalScope('organization')->sole();

    dispatch_sync(new ProvisionService($service->id));

    $service->refresh();

    expect($service->status)->toBe(ServiceStatus::Active)
        ->and($service->external_id)->not->toBeNull()
        ->and($service->server_id)->not->toBeNull();
});

it('does not queue a setup for a product an operator sets up by hand', function (): void {
    Queue::fake();

    $this->product->update(['auto_setup' => AutoSetup::None->value]);

    app(RecordPayment::class)->handle($this->invoice, new RecordPaymentRequest(Money::ofMinor(1499, 'EUR')));

    // The service still exists — an operator has something to work from.
    expect(Service::query()->withoutGlobalScope('organization')->count())->toBe(1);

    Queue::assertNotPushed(ProvisionService::class);
});

it('does not queue a setup for a product with no module', function (): void {
    Queue::fake();

    $this->product->update(['provisioning_module' => null]);

    app(RecordPayment::class)->handle($this->invoice, new RecordPaymentRequest(Money::ofMinor(1499, 'EUR')));

    Queue::assertNotPushed(ProvisionService::class);
});

it('creates one service when the same order is paid twice', function (): void {
    Queue::fake();

    // A webhook confirming a transfer an operator already recorded.
    app(RecordPayment::class)->handle($this->invoice, new RecordPaymentRequest(Money::ofMinor(1000, 'EUR')));
    app(RecordPayment::class)->handle(
        $this->invoice->fresh() ?? $this->invoice,
        new RecordPaymentRequest(Money::ofMinor(499, 'EUR')),
    );

    expect(Service::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

it('leaves the order alone when the invoice is only partly paid', function (): void {
    Queue::fake();

    app(RecordPayment::class)->handle($this->invoice, new RecordPaymentRequest(Money::ofMinor(500, 'EUR')));

    expect($this->order->fresh()?->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(Service::query()->withoutGlobalScope('organization')->count())->toBe(0);

    Queue::assertNotPushed(ProvisionService::class);
});
