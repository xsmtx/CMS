<?php

declare(strict_types=1);

use App\Application\Provisioning\CreateServicesForOrder;
use App\Application\Provisioning\RunServiceOperation;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Provisioning\AutoSetup;
use App\Domain\Provisioning\OperationOutcome;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\ServiceOperation;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Ordering\Models\OrderItemOption;
use App\Infrastructure\Provisioning\Jobs\ProvisionService;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceEvent;
use App\Infrastructure\Provisioning\ModuleRegistry;
use Database\Seeders\ProviderOrganizationSeeder;
use Tests\Support\FakeProvisioningModule;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->module = new FakeProvisioningModule;

    $registry = new ModuleRegistry;
    $registry->register($this->module);
    $this->app->instance(ModuleRegistry::class, $registry);

    $this->group = ServerGroup::factory()->create();
    $this->server = Server::factory()->inGroup($this->group)->create(['module' => 'fake']);

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
        'status' => OrderStatus::Paid->value,
        'currency_code' => 'EUR',
    ]);

    $this->item = OrderItem::factory()->create([
        'organization_id' => $this->order->organization_id,
        'order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'name' => 'Starter Plan',
        'billing_cycle' => BillingCycle::Monthly->value,
        'line_recurring_minor' => 1499,
        'domain' => 'example.test',
    ]);
});

it('creates a service from a paid order, copying rather than referencing', function (): void {
    OrderItemOption::factory()->create([
        'organization_id' => $this->item->organization_id,
        'order_item_id' => $this->item->id,
        'group_name' => 'Control panel',
        'label' => 'cPanel',
        'value' => 'cpanel',
    ]);

    $services = app(CreateServicesForOrder::class)->handle($this->order);

    expect($services)->toHaveCount(1);

    $service = $services[0];

    // The product is renamed and repriced afterwards; the service keeps
    // what was actually bought.
    $this->product->update(['name' => 'Renamed Plan']);

    expect($service->fresh()?->name)->toBe('Starter Plan')
        ->and($service->status)->toBe(ServiceStatus::Pending)
        ->and($service->recurring->minorUnits)->toBe(1499)
        ->and($service->billing_cycle)->toBe(BillingCycle::Monthly)
        ->and($service->domain)->toBe('example.test')
        ->and($service->package)->toBe('starter')
        ->and($service->next_due_on?->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($service->options()->count())->toBe(1)
        ->and($service->configuration)->toBe(['Control panel' => 'cPanel']);
});

it('creates one service however many times the order is paid', function (): void {
    // A replayed webhook, an operator recording a transfer a gateway then
    // confirms: the same order reaches `paid` more than once.
    app(CreateServicesForOrder::class)->handle($this->order);
    app(CreateServicesForOrder::class)->handle($this->order->fresh() ?? $this->order);

    expect(Service::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

it('places the service and stores what the provider called it', function (): void {
    $service = app(CreateServicesForOrder::class)->handle($this->order)[0];

    $result = app(RunServiceOperation::class)->provision($service);

    $service->refresh();

    expect($result->outcome)->toBe(OperationOutcome::Succeeded)
        ->and($service->status)->toBe(ServiceStatus::Active)
        ->and($service->server_id)->toBe($this->server->id)
        ->and($service->external_id)->not->toBeNull()
        ->and($service->provisioned_at)->not->toBeNull()
        // Issued by the provider, encrypted at rest.
        ->and($service->password)->toBe('issued-by-the-provider');
});

it('records every attempt against the service', function (): void {
    $service = app(CreateServicesForOrder::class)->handle($this->order)[0];

    app(RunServiceOperation::class)->provision($service);

    $event = ServiceEvent::query()->withoutGlobalScope('organization')->sole();

    expect($event->operation)->toBe(ServiceOperation::Create)
        ->and($event->outcome)->toBe(OperationOutcome::Succeeded);
});

it('treats an account that already exists as a success', function (): void {
    $service = app(CreateServicesForOrder::class)->handle($this->order)[0];

    // The job timed out after the provider had already created it. Running
    // again must reach the same place rather than failing forever.
    $this->module->willReturn(ProvisioningResult::alreadyDone('acct_existing'));

    $result = app(RunServiceOperation::class)->provision($service);

    expect($result->outcome)->toBe(OperationOutcome::AlreadyDone)
        ->and($service->fresh()?->status)->toBe(ServiceStatus::Active)
        ->and($service->fresh()?->external_id)->toBe('acct_existing');
});

it('leaves a failed setup in failed, with the reason', function (): void {
    $service = app(CreateServicesForOrder::class)->handle($this->order)[0];

    $this->module->willReturn(ProvisioningResult::failed('Disk quota exceeded on node'));

    $result = app(RunServiceOperation::class)->provision($service);

    $service->refresh();

    expect($result->isSuccessful())->toBeFalse()
        // A state an operator can find, not an exception in a log.
        ->and($service->status)->toBe(ServiceStatus::Failed)
        ->and($service->failure_reason)->toContain('Disk quota exceeded');
});

it('retries a failed service without creating a second account', function (): void {
    $service = app(CreateServicesForOrder::class)->handle($this->order)[0];

    $this->module->willReturn(ProvisioningResult::failed('Node unreachable'));
    app(RunServiceOperation::class)->provision($service);

    $this->module->willReturn(ProvisioningResult::alreadyDone('acct_from_first_try'));
    app(RunServiceOperation::class)->provision($service->fresh() ?? $service);

    expect($service->fresh()?->status)->toBe(ServiceStatus::Active)
        ->and(Service::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

it('does not provision a service that is already active', function (): void {
    $service = app(CreateServicesForOrder::class)->handle($this->order)[0];

    app(RunServiceOperation::class)->provision($service);

    // A job that ran twice, or a webhook redelivered an hour later.
    dispatch_sync(new ProvisionService($service->id));

    expect($this->module->callsTo('create'))->toBe(1);
});

it('suspends, unsuspends and terminates through the same path', function (): void {
    $service = app(CreateServicesForOrder::class)->handle($this->order)[0];
    $operations = app(RunServiceOperation::class);

    $operations->provision($service);

    $operations->suspend($service, 'Non-payment');
    expect($service->fresh()?->status)->toBe(ServiceStatus::Suspended)
        ->and($service->fresh()?->suspension_reason)->toBe('Non-payment');

    $operations->unsuspend($service->fresh() ?? $service);
    expect($service->fresh()?->status)->toBe(ServiceStatus::Active);

    $operations->terminate($service->fresh() ?? $service);
    expect($service->fresh()?->status)->toBe(ServiceStatus::Terminated)
        ->and($this->module->callsTo('terminate'))->toBe(1);
});

it('terminates a service that never existed remotely without calling anybody', function (): void {
    $service = app(CreateServicesForOrder::class)->handle($this->order)[0];

    app(RunServiceOperation::class)->terminate($service);

    expect($service->fresh()?->status)->toBe(ServiceStatus::Terminated)
        // Asking a provider to delete something it was never told about
        // produces an error that means nothing.
        ->and($this->module->callsTo('terminate'))->toBe(0);
});

it('never puts a credential in an event record', function (): void {
    $service = app(CreateServicesForOrder::class)->handle($this->order)[0];

    $this->module->willReturn(ProvisioningResult::failed(
        'createacct failed for user bob with password=hunter2seventeen',
    ));

    app(RunServiceOperation::class)->provision($service);

    $event = ServiceEvent::query()->withoutGlobalScope('organization')
        ->where('outcome', OperationOutcome::Failed->value)
        ->sole();

    expect($event->message)->not->toContain('hunter2seventeen');
});
