<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\RecordedRun;
use App\Application\Automation\Runs\GenerateRenewalInvoices;
use App\Application\Automation\Runs\RunDunningSequence;
use App\Application\Health\HealthChecks;
use App\Application\Health\MaintenanceMode;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Health\HealthState;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Automation\Models\DunningStep;
use App\Infrastructure\Automation\Models\InvoiceDunningStep;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Notifications\ChannelRegistry;
use App\Infrastructure\Notifications\Channels\DatabaseChannel;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Platform\Models\PlatformState;
use App\Infrastructure\Provisioning\Models\Service;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    // Only the in-app channel, so the tests exercise the sequence rather
    // than a mail transport.
    $registry = new ChannelRegistry;
    $registry->register(new DatabaseChannel);
    $this->app->instance(ChannelRegistry::class, $registry);

    $this->customer = Customer::factory()->create();
    Contact::factory()->forCustomer($this->customer)->primary()->create();

    $this->runner = app(RecordedRun::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

function overdueInvoice(Customer $customer, int $daysPastDue, ?Order $order = null): Invoice
{
    return Invoice::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'order_id' => $order?->id,
        'status' => InvoiceStatus::Overdue->value,
        'due_on' => now()->subDays($daysPastDue)->toDateString(),
    ]);
}

it('sends the reminder a step asks for, once', function (): void {
    overdueInvoice($this->customer, 3);

    DunningStep::factory()->notify(1, NotificationEvent::PaymentFailed)->create();

    $first = $this->runner->handle(AutomationTask::Dunning, app(RunDunningSequence::class));
    $second = $this->runner->handle(AutomationTask::Dunning, app(RunDunningSequence::class));

    // The whole point of `invoice_dunning_steps`: a step runs once per
    // invoice however often the sweep runs.
    expect($first->changed)->toBe(1)
        ->and($second->changed)->toBe(0)
        ->and(NotificationDelivery::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

it('does not run a step that is not due yet', function (): void {
    overdueInvoice($this->customer, 1);

    DunningStep::factory()->notify(7, NotificationEvent::PaymentFailed)->create();

    $record = $this->runner->handle(AutomationTask::Dunning, app(RunDunningSequence::class));

    expect($record->changed)->toBe(0)
        ->and(InvoiceDunningStep::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('catches up on a step that should have run while the scheduler was down', function (): void {
    // Thirty days past due, and the seven-day step has never run. Asking
    // "which invoices are exactly seven days old" would skip this forever.
    overdueInvoice($this->customer, 30);

    DunningStep::factory()->notify(7, NotificationEvent::PaymentFailed)->create();

    $record = $this->runner->handle(AutomationTask::Dunning, app(RunDunningSequence::class));

    expect($record->changed)->toBe(1);
});

it('suspends the services on the order an unpaid invoice was for', function (): void {
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'organization_id' => $this->customer->organization_id,
    ]);

    $service = Service::factory()->create([
        'customer_id' => $this->customer->id,
        'organization_id' => $this->customer->organization_id,
        'order_id' => $order->id,
        'status' => ServiceStatus::Active->value,
    ]);

    overdueInvoice($this->customer, 10, $order);

    DunningStep::factory()->suspend(7)->create();

    $this->runner->handle(AutomationTask::Dunning, app(RunDunningSequence::class));

    expect($service->fresh()?->status)->toBe(ServiceStatus::Suspended);
});

it('does nothing at all when no sequence is configured', function (): void {
    overdueInvoice($this->customer, 30);

    $record = $this->runner->handle(AutomationTask::Dunning, app(RunDunningSequence::class));

    // A business that wants a human decision before every suspension
    // expresses it by having no steps, and that has to be quiet.
    expect($record->examined)->toBe(1)
        ->and($record->changed)->toBe(0)
        ->and($record->skipped)->toBe(1);
});

it('lets an operator add and remove a step', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/automation/dunning', [
            'offset_days' => -3,
            'action' => 'notify',
            'event' => NotificationEvent::InvoiceIssued->value,
        ])
        ->assertRedirect();

    $step = DunningStep::query()->sole();

    expect($step->offset_days)->toBe(-3);

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/automation/dunning/'.$step->id)
        ->assertRedirect();

    expect(DunningStep::query()->count())->toBe(0);
});

it('refuses a notify step with no message', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/automation/dunning', ['offset_days' => -3, 'action' => 'notify'])
        ->assertSessionHasErrors('event');
});

it('shows staff the tasks even before any of them has run', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/automation')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Automation/Index')
            // A list of past runs alone cannot answer "is the renewal sweep
            // working", because the answer there is an absence.
            // Sixteen now: Phase 14 added the licence heartbeat, Phase A the
            // resource projection and the telemetry sweep, Phase B the adapter
            // health check, Phase C the topology discovery, the grant expiry
            // and the attack collection, and Phase D the alert evaluation. A
            // count rather than a list on purpose: the screen has to offer
            // every task the command can run, and a new one joins both or
            // neither — which is what this has caught seven phases running.
            ->has('tasks', 16)
            ->where('tasks.0.lastRun', null));
});

it('runs a task from the screen and records it', function (): void {
    Invoice::factory()->create([
        'status' => InvoiceStatus::Unpaid->value,
        'due_on' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/automation/overdue/run')
        ->assertRedirect();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/automation')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('runs', 1)
            ->where('runs.0.changed', 1));
});

it('reports the health of an installation without returning any configuration', function (): void {
    $reports = app(HealthChecks::class)->run();

    expect($reports)->not->toBeEmpty();

    foreach ($reports as $report) {
        foreach ($report->measurements as $value) {
            // Numbers and short words only. A DSN, a host or a key would
            // be published to whoever is looking over the operator's
            // shoulder.
            expect((string) $value)->not->toContain('://')
                ->and((string) $value)->not->toContain('password');
        }
    }
});

it('says the scheduler has never reported rather than that it died', function (): void {
    $reports = app(HealthChecks::class)->run();

    $scheduler = collect($reports)->firstWhere('key', 'scheduler');

    // A fresh installation has not run the scheduler yet. Telling an
    // operator it has died on day one teaches them to ignore the line.
    expect($scheduler?->state)->toBe(HealthState::Degraded);
});

it('is healthy about the scheduler once it has reported in', function (): void {
    $this->artisan('platform:heartbeat')->assertSuccessful();

    $reports = app(HealthChecks::class)->run();

    expect(collect($reports)->firstWhere('key', 'scheduler')?->state)->toBe(HealthState::Ok)
        ->and(PlatformState::query()->find(PlatformState::SCHEDULER_HEARTBEAT))->not->toBeNull();
});

it('closes the storefront for the public and leaves staff a way in', function (): void {
    app(MaintenanceMode::class)->enable('Back in an hour.');

    $this->get('/')->assertStatus(503);

    // The admin area is deliberately not behind it: a switch that locks
    // out the only people who can unset it is a switch nobody will use.
    $this->actingAs($this->admin, 'staff')->get('/admin/health')->assertOk();
});

it('turns itself off once the window has passed', function (): void {
    app(MaintenanceMode::class)->enable('Back shortly.', now()->subMinute()->toImmutable());

    // An operator who set an end time should not have to come back and
    // clear it.
    expect(app(MaintenanceMode::class)->isActive())->toBeFalse();

    $this->get('/')->assertOk();
});

it('lets staff turn maintenance on and off from the health screen', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/health/maintenance', ['enabled' => true, 'message' => 'Upgrading the panel.'])
        ->assertRedirect();

    expect(app(MaintenanceMode::class)->isActive())->toBeTrue();

    $this->actingAs($this->admin, 'staff')
        ->put('/admin/health/maintenance', ['enabled' => false])
        ->assertRedirect();

    expect(app(MaintenanceMode::class)->isActive())->toBeFalse();
});

it('refuses to turn maintenance on with nothing to say', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->put('/admin/health/maintenance', ['enabled' => true, 'message' => ''])
        ->assertSessionHasErrors('message');
});

it('shows the sequence with each step described in words', function (): void {
    DunningStep::factory()->notify(-3, NotificationEvent::InvoiceIssued)->create();
    DunningStep::factory()->suspend(7)->create();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/automation/dunning')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Automation/Dunning')
            ->has('steps', 2)
            // "3 days before due" rather than "-3", because an operator
            // reading a minus sign has to stop and work out which way it
            // points.
            ->where('steps.0.when', '3 days before due'));
});

it('suspends the services a renewal invoice is for, which has no order', function (): void {
    $service = Service::factory()->create([
        'customer_id' => $this->customer->id,
        'organization_id' => $this->customer->organization_id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'next_due_on' => now()->addDay()->toDateString(),
    ]);

    app(RecordedRun::class)->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    $invoice = Invoice::query()->withoutGlobalScope('organization')
        ->whereNull('order_id')
        ->sole();

    // Make it long overdue. A renewal invoice has no order behind it, so
    // reading only `order_id` would find nothing on exactly the invoices
    // dunning exists for.
    $invoice->forceFill([
        'status' => InvoiceStatus::Overdue->value,
        'due_on' => now()->subDays(10)->toDateString(),
    ])->save();

    DunningStep::factory()->suspend(7)->create();

    app(RecordedRun::class)->handle(AutomationTask::Dunning, app(RunDunningSequence::class));

    expect($service->fresh()?->status)->toBe(ServiceStatus::Suspended);
});

/**
 * Three answers a customer is allowed to give about their own billing, and
 * each one is read by code that exists.
 */
it('says nothing to a customer whose overdue notices are turned off', function (): void {
    $this->customer->forceFill(['send_overdue_notices' => false])->save();

    overdueInvoice($this->customer, 3);

    DunningStep::factory()->notify(1, NotificationEvent::PaymentFailed)->create();

    $record = $this->runner->handle(AutomationTask::Dunning, app(RunDunningSequence::class));

    expect($record->changed)->toBe(0)
        ->and(NotificationDelivery::query()->withoutGlobalScope('organization')->count())->toBe(0)
        // Not recorded as run: turning notices back on next week must not
        // mean this customer silently skipped the step forever.
        ->and(InvoiceDunningStep::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('does not suspend a customer who is chased by telephone', function (): void {
    $this->customer->forceFill(['automatic_suspension' => false])->save();

    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'organization_id' => $this->customer->organization_id,
    ]);

    $service = Service::factory()->create([
        'customer_id' => $this->customer->id,
        'organization_id' => $this->customer->organization_id,
        'order_id' => $order->id,
        'status' => ServiceStatus::Active->value,
    ]);

    overdueInvoice($this->customer, 10, $order);

    DunningStep::factory()->suspend(7)->create();

    $this->runner->handle(AutomationTask::Dunning, app(RunDunningSequence::class));

    expect($service->fresh()?->status)->toBe(ServiceStatus::Active);
});

it('still chases a customer who said nothing either way', function (): void {
    // The defaults are what the platform did before these columns existed.
    expect($this->customer->send_overdue_notices)->toBeTrue()
        ->and($this->customer->automatic_suspension)->toBeTrue();

    overdueInvoice($this->customer, 3);

    DunningStep::factory()->notify(1, NotificationEvent::PaymentFailed)->create();

    $record = $this->runner->handle(AutomationTask::Dunning, app(RunDunningSequence::class));

    expect($record->changed)->toBe(1);
});
