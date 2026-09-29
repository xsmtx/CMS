<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\TaskRegistry;
use App\Application\Intelligence\DetectLeakage;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Automation\AutomationTask;
use App\Domain\Billing\TransactionKind;
use App\Domain\Intelligence\LeakageKind;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\AddonStatus;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\FindingDismissal;
use App\Infrastructure\Intelligence\Models\LeakageFinding;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceAddon;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Revenue leakage (§21).
 *
 * **No adapter, no provider, no model** — four joins over rows this platform
 * already owns, which is what makes this the one family in Phase H whose
 * answers do not depend on an untested parse.
 *
 * The distinction this file protects is that **a finding is never an
 * accusation**. A charity given free hosting, a domain held for a customer
 * arriving in March, a payment taken on account: all three appear here and
 * all three are deliberate, which is why a dismissal exists and why nothing
 * on the screen raises an invoice.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    /*
     * No `organization_id` here on purpose. `CustomerFactory` creates the
     * customer its own child organization, which is what a real customer
     * has — forcing it into the provider's own organization is what hid a
     * scoping bug that made these sweeps find nothing on a real
     * installation.
     */
    $this->customer = Customer::factory()->create(['company_name' => 'Acme Ltd']);

    $this->leakage = app(DetectLeakage::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function anOverdueService(Customer $customer, int $minor = 1990, ?string $through = null): Service
{
    return Service::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'name' => 'shop hosting',
        'domain' => 'shop.example',
        'status' => ServiceStatus::Active,
        'currency_code' => 'EUR',
        'recurring_minor' => $minor,
        'next_due_on' => CarbonImmutable::now()->subDays(5),
        'renewal_invoiced_through' => $through,
    ]);
}

function invoiceLineFor(Customer $customer, string $type, string $id, ?CarbonImmutable $at = null): void
{
    $invoice = Invoice::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
    ]);

    $item = InvoiceItem::factory()->create([
        'organization_id' => $customer->organization_id,
        'invoice_id' => $invoice->id,
        'subject_type' => $type,
        'subject_id' => $id,
    ]);

    // The join asks when the line was raised, so a fixture that means
    // "invoiced since it fell due" has to say when.
    $item->forceFill(['created_at' => $at ?? CarbonImmutable::now()])->save();
}

it('finds an active service nothing has invoiced', function (): void {
    anOverdueService($this->customer);

    $leaks = $this->leakage->handle($this->provider->id);

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->kind)->toBe(LeakageKind::ServiceNotBilled)
        ->and($leaks[0]->label)->toBe('shop.example')
        ->and($leaks[0]->amount->minorUnits)->toBe(1990)
        // Whose it is, because that is the question a commercial screen is
        // opened to answer.
        ->and($leaks[0]->customerLabel)->toBe('Acme Ltd');
});

it('says nothing about a service an invoice has mentioned since it fell due', function (): void {
    $service = anOverdueService($this->customer);

    invoiceLineFor($this->customer, $service::class, $service->id);

    expect($this->leakage->handle($this->provider->id))->toBe([]);
});

/**
 * The column the renewal sweep and the importer both write. A service
 * invoiced through a date after its due date is simply paid up.
 */
it('says nothing about a service billing has already got past', function (): void {
    anOverdueService($this->customer, through: CarbonImmutable::now()->addMonth()->toDateString());

    expect($this->leakage->handle($this->provider->id))->toBe([]);
});

/**
 * Zero means free, which is a price somebody set — not a leak. ADR 0021's
 * rule that absence and zero say different things.
 */
it('says nothing about a service given away', function (): void {
    anOverdueService($this->customer, minor: 0);

    expect($this->leakage->handle($this->provider->id))->toBe([]);
});

/**
 * A line raised before the period began is last month's invoice, not this
 * one's — and reading it as this one's would hide the leak entirely.
 */
it('does not count last month’s invoice as this month’s', function (): void {
    $service = anOverdueService($this->customer);

    invoiceLineFor(
        $this->customer,
        $service::class,
        $service->id,
        CarbonImmutable::now()->subMonths(2),
    );

    expect($this->leakage->handle($this->provider->id))->toHaveCount(1);
});

it('finds an addon left off an invoice its service was on', function (): void {
    $service = anOverdueService($this->customer);
    invoiceLineFor($this->customer, $service::class, $service->id);

    ServiceAddon::factory()->create([
        'organization_id' => $this->provider->id,
        'customer_id' => $this->customer->id,
        'service_id' => $service->id,
        'name' => 'Extra backup space',
        'status' => AddonStatus::Active,
        'currency_code' => 'EUR',
        'recurring_minor' => 500,
        'next_due_on' => CarbonImmutable::now()->subDays(5),
    ]);

    $leaks = $this->leakage->handle($this->provider->id);

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->kind)->toBe(LeakageKind::AddonNotBilled)
        ->and($leaks[0]->detail['service'])->toBe('shop.example');
});

/**
 * An addon on a service nothing is invoicing is the *service's* finding.
 * Reporting both would count one problem twice and double the figure an
 * operator reads off the strip.
 */
it('does not report an addon whose service is itself not billed', function (): void {
    $service = anOverdueService($this->customer);

    ServiceAddon::factory()->create([
        'organization_id' => $this->provider->id,
        'customer_id' => $this->customer->id,
        'service_id' => $service->id,
        'status' => AddonStatus::Active,
        'currency_code' => 'EUR',
        'recurring_minor' => 500,
        'next_due_on' => CarbonImmutable::now()->subDays(5),
    ]);

    $leaks = $this->leakage->handle($this->provider->id);

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->kind)->toBe(LeakageKind::ServiceNotBilled);
});

it('finds money attached to no invoice', function (): void {
    Transaction::query()->create([
        'organization_id' => $this->provider->id,
        'customer_id' => $this->customer->id,
        'invoice_id' => null,
        'kind' => TransactionKind::Payment,
        'currency_code' => 'EUR',
        'amount_minor' => 4500,
        'credit_balance_minor' => 0,
        'fees_minor' => 0,
        'reference' => 'ch_9f21',
        'occurred_at' => CarbonImmutable::now()->subDays(5),
    ]);

    $leaks = $this->leakage->handle($this->provider->id);

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->kind)->toBe(LeakageKind::UnmatchedPayment)
        ->and($leaks[0]->label)->toBe('ch_9f21');
});

/**
 * A payment taken this morning that nobody has allocated yet is a
 * bookkeeper's afternoon, not a leak.
 */
it('leaves this morning’s payment alone', function (): void {
    Transaction::query()->create([
        'organization_id' => $this->provider->id,
        'customer_id' => $this->customer->id,
        'invoice_id' => null,
        'kind' => TransactionKind::Payment,
        'currency_code' => 'EUR',
        'amount_minor' => 4500,
        'credit_balance_minor' => 0,
        'fees_minor' => 0,
        'occurred_at' => CarbonImmutable::now()->subHours(3),
    ]);

    expect($this->leakage->handle($this->provider->id))->toBe([]);
});

it('raises, keeps and clears', function (): void {
    $service = anOverdueService($this->customer);

    $run = app(TaskRegistry::class)->resolve(AutomationTask::Leakage);

    $run->handle();
    expect(LeakageFinding::query()->count())->toBe(1);

    $run->handle();
    expect(LeakageFinding::query()->count())->toBe(1);

    invoiceLineFor($this->customer, $service::class, $service->id);
    $run->handle();

    $finding = LeakageFinding::query()->sole();

    // Cleared, never deleted: "we fixed that, when?" is the question asked
    // the next time the figure moves.
    expect($finding->cleared_at)->not->toBeNull()
        ->and($finding->cleared_token)->toBe($finding->id);
});

/**
 * What is at stake is a fact about today; how long it has been true is not.
 */
it('refreshes the amount and leaves the clock alone', function (): void {
    $service = anOverdueService($this->customer);
    $run = app(TaskRegistry::class)->resolve(AutomationTask::Leakage);

    $run->handle();
    $first = LeakageFinding::query()->sole();

    $service->forceFill(['recurring_minor' => 2990])->save();
    $run->handle();

    $finding = LeakageFinding::query()->sole();

    expect($finding->amount_minor)->toBe(2990)
        ->and($finding->first_seen_at->toDateTimeString())
        ->toBe($first->first_seen_at->toDateTimeString());
});

it('stops raising one somebody said was deliberate', function (): void {
    anOverdueService($this->customer);
    $run = app(TaskRegistry::class)->resolve(AutomationTask::Leakage);
    $run->handle();

    $finding = LeakageFinding::query()->sole();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/intelligence/leakage/{$finding->id}/dismiss", [
            'reason' => 'Free hosting for the local charity.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $run->handle();

    expect(LeakageFinding::query()->open()->count())->toBe(0)
        // The same table a reconciliation dismissal lives in: the act is
        // identical and `source` says which family.
        ->and(FindingDismissal::query()->where('source', DetectLeakage::Resource)->count())->toBe(1);
});

it('drives the screen and totals by currency, never across them', function (): void {
    anOverdueService($this->customer);

    Transaction::query()->create([
        'organization_id' => $this->provider->id,
        'customer_id' => $this->customer->id,
        'invoice_id' => null,
        'kind' => TransactionKind::Payment,
        'currency_code' => 'TRY',
        'amount_minor' => 120000,
        'credit_balance_minor' => 0,
        'fees_minor' => 0,
        'occurred_at' => CarbonImmutable::now()->subDays(5),
    ]);

    app(TaskRegistry::class)->resolve(AutomationTask::Leakage)->handle();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/leakage')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Intelligence/Leakage')
            ->has('findings.data', 2)
            // Two currencies, two figures. There is no rate in this product
            // and a total across them is a figure that means nothing.
            ->has('atStake', 2)
            // Two fields, always.
            ->where('findings.data.0.kindTone', 'warning'));
});

it('refuses the screen to somebody without the commercial permission', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/intelligence/leakage')
        ->assertForbidden();
});

it('never raises an invoice for what it found', function (): void {
    anOverdueService($this->customer);

    $before = Invoice::query()->count();
    app(TaskRegistry::class)->resolve(AutomationTask::Leakage)->handle();

    // Charging a customer nobody decided to charge is the one thing this
    // family must not be able to do.
    expect(Invoice::query()->count())->toBe($before);
});
