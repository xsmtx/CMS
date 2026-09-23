<?php

declare(strict_types=1);

use App\Application\Automation\RecordedRun;
use App\Application\Automation\Runs\GenerateRenewalInvoices;
use App\Application\Automation\Runs\MarkInvoicesOverdue;
use App\Application\Automation\TaskRegistry;
use App\Domain\Automation\AutomationTask;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunStatus;
use App\Domain\Automation\RunSummary;
use App\Domain\Billing\Events\PaymentReceived;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Automation\Models\AutomationRunItemRecord;
use App\Infrastructure\Automation\Models\AutomationRunRecord;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Service;
use Database\Seeders\ProviderOrganizationSeeder;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->runner = app(RecordedRun::class);
});

it('writes a record even for a run that changed nothing', function (): void {
    $record = $this->runner->handle(AutomationTask::Overdue, app(MarkInvoicesOverdue::class));

    // "Examined 0, changed 0" is the answer to "why was nobody chased last
    // night". A recorder that only wrote interesting runs could not give it.
    expect($record->status)->toBe(RunStatus::Completed)
        ->and($record->examined)->toBe(0)
        ->and($record->changed)->toBe(0)
        ->and($record->finished_at)->not->toBeNull();
});

it('records a run that could not finish, with its error redacted', function (): void {
    $broken = new class implements AutomationRun
    {
        public function handle(): RunSummary
        {
            throw new RuntimeException('provider refused: password=hunter2');
        }
    };

    $record = $this->runner->handle(AutomationTask::Sync, $broken);

    expect($record->status)->toBe(RunStatus::Failed)
        ->and($record->error)->not->toContain('hunter2')
        ->and($record->finished_at)->not->toBeNull();
});

it('writes a detail row for what changed and nothing for what was skipped', function (): void {
    $noisy = new class implements AutomationRun
    {
        public function handle(): RunSummary
        {
            $summary = new RunSummary;

            for ($i = 0; $i < 50; $i++) {
                $summary = $summary->examining()->skipping();
            }

            return $summary->examining()->changing(new RunItem(
                ItemOutcome::Changed,
                null,
                null,
                'INV-000001',
            ));
        }
    };

    $record = $this->runner->handle(AutomationTask::Dunning, $noisy);

    // Fifty skips are a number on the run, not fifty rows nobody reads.
    expect($record->skipped)->toBe(50)
        ->and(AutomationRunItemRecord::query()->where('run_id', $record->id)->count())->toBe(1);
});

it('moves an unpaid invoice past its due date to overdue', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Unpaid->value,
        'due_on' => now()->subDays(3)->toDateString(),
    ]);

    $record = $this->runner->handle(AutomationTask::Overdue, app(MarkInvoicesOverdue::class));

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Overdue)
        ->and($record->changed)->toBe(1);
});

it('leaves an invoice that is not yet due alone', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Unpaid->value,
        'due_on' => now()->addDays(3)->toDateString(),
    ]);

    $this->runner->handle(AutomationTask::Overdue, app(MarkInvoicesOverdue::class));

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Unpaid);
});

it('changes nothing the second time it runs', function (): void {
    Invoice::factory()->create([
        'status' => InvoiceStatus::Unpaid->value,
        'due_on' => now()->subDays(3)->toDateString(),
    ]);

    $first = $this->runner->handle(AutomationTask::Overdue, app(MarkInvoicesOverdue::class));
    $second = $this->runner->handle(AutomationTask::Overdue, app(MarkInvoicesOverdue::class));

    // The rule the whole phase rests on: a question about state, asked
    // twice, has the same answer the second time.
    expect($first->changed)->toBe(1)
        ->and($second->changed)->toBe(0)
        ->and($second->examined)->toBe(0);
});

it('raises one renewal invoice for a customer with two services', function (): void {
    $customer = Customer::factory()->create();

    Service::factory()->forCustomer($customer)->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 1500,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    Service::factory()->forCustomer($customer)->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 500,
        'next_due_on' => now()->addDays(5)->toDateString(),
    ]);

    $record = $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    $invoices = Invoice::query()->withoutGlobalScope('organization')
        ->where('customer_id', $customer->id)
        ->get();

    // One document, because that is what a bank transfer can pay.
    expect($invoices)->toHaveCount(1)
        ->and($invoices->first()?->total->minorUnits)->toBe(2000)
        ->and($record->examined)->toBe(2)
        ->and($record->changed)->toBe(1);
});

it('does not invoice the same term twice', function (): void {
    Service::factory()->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));
    $second = $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    expect($second->changed)->toBe(0)
        ->and(Invoice::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

it('writes what each renewal line is for', function (): void {
    $service = Service::factory()->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    $line = InvoiceItem::query()->withoutGlobalScope('organization')->sole();

    // The way back from a paid invoice to the row whose date it advances.
    // The description is prose that was already a copy.
    expect($line->subject_type)->toBe(Service::class)
        ->and($line->subject_id)->toBe($service->id)
        ->and($line->period_end)->not->toBeNull();
});

it('leaves a one-time service out of the renewal sweep', function (): void {
    Service::factory()->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::OneTime->value,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    $record = $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    expect($record->examined)->toBe(0)
        ->and(Invoice::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('resolves every task the enum offers', function (): void {
    $registry = app(TaskRegistry::class);

    foreach (AutomationTask::cases() as $task) {
        // A screen that can offer a task the command cannot run is worse
        // than a screen with fewer buttons.
        expect($registry->resolve($task))->toBeInstanceOf(AutomationRun::class);
    }
});

it('runs a task from the command line and says what it did', function (): void {
    Invoice::factory()->create([
        'status' => InvoiceStatus::Unpaid->value,
        'due_on' => now()->subDay()->toDateString(),
    ]);

    $this->artisan('platform:run overdue')->assertSuccessful();

    expect(AutomationRunRecord::query()->forTask(AutomationTask::Overdue)->sole()->changed)->toBe(1);
});

it('refuses a task nobody has heard of', function (): void {
    $this->artisan('platform:run not-a-task')->assertFailed();
});

it('advances the service date only once the renewal is paid', function (): void {
    $service = Service::factory()->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    $before = $service->fresh()?->next_due_on;

    // Raising the invoice is a request. It must not extend the service, or
    // dunning would have nothing left to chase.
    expect($before?->toDateString())->toBe(now()->addDays(3)->toDateString());

    $invoice = Invoice::query()->withoutGlobalScope('organization')->sole();
    $line = InvoiceItem::query()->withoutGlobalScope('organization')->sole();

    $invoice->forceFill(['status' => InvoiceStatus::Paid->value])->save();

    event(new PaymentReceived(
        paymentId: 'payment',
        invoiceId: $invoice->id,
        organizationId: $invoice->organization_id,
    ));

    expect($service->fresh()?->next_due_on?->toDateString())
        ->toBe($line->period_end?->toDateString());
});

it('does not advance anything for a part payment', function (): void {
    $service = Service::factory()->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    $invoice = Invoice::query()->withoutGlobalScope('organization')->sole();
    $invoice->forceFill(['status' => InvoiceStatus::PartiallyPaid->value])->save();

    event(new PaymentReceived(
        paymentId: 'payment',
        invoiceId: $invoice->id,
        organizationId: $invoice->organization_id,
    ));

    expect($service->fresh()?->next_due_on?->toDateString())
        ->toBe(now()->addDays(3)->toDateString());
});

/**
 * The other answer, for a customer who passes each line to a different
 * cost centre and asked to be billed that way.
 */
it('raises one invoice per item for a customer who asked for separate invoices', function (): void {
    $customer = Customer::factory()->create(['separate_invoices' => true]);

    Service::factory()->forCustomer($customer)->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 1500,
        'next_due_on' => now()->addDays(3)->toDateString(),
    ]);

    Service::factory()->forCustomer($customer)->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 500,
        'next_due_on' => now()->addDays(5)->toDateString(),
    ]);

    $record = $this->runner->handle(AutomationTask::Renewals, app(GenerateRenewalInvoices::class));

    $invoices = Invoice::query()->withoutGlobalScope('organization')
        ->where('customer_id', $customer->id)
        ->get();

    expect($invoices)->toHaveCount(2)
        ->and($invoices->sum(fn (Invoice $invoice): int => $invoice->total->minorUnits))->toBe(2000)
        ->and($record->changed)->toBe(2);
});
