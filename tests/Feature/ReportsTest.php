<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\ResellerAttributes;
use App\Application\Reports\ReportPeriod;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\TransactionKind;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * The monthly review.
 *
 * Three rules are under test throughout, and each is a way a report lies:
 *
 * - **Money is per currency and never summed.** There is no rate in this
 *   product, so a total across currencies is a number that means nothing — and
 *   it would be the number somebody quotes.
 * - **A figure is what its label says.** ARR is twelve times the month, not a
 *   year of collected revenue; aging is measured from the due date, not the
 *   issue date. Printing one and calling it the other is the classic reporting
 *   lie.
 * - **The boundary applies.** The same screen answers the provider's question
 *   and a reseller's, and a reseller reading another reseller's revenue would be
 *   the worst possible leak.
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
});

// --- the period -------------------------------------------------------------

it('reads a backwards period the way it was meant', function (): void {
    $period = ReportPeriod::fromInput('2026-09-30', '2026-09-01');

    expect($period->from->toDateString())->toBe('2026-09-01');
    expect($period->to->toDateString())->toBe('2026-09-30');
});

/**
 * A report of "1 September to 30 September" that stopped at midnight on the 30th
 * would quietly lose a day's revenue, and the number would still look plausible.
 */
it('includes both days of the period', function (): void {
    $period = ReportPeriod::fromInput('2026-09-01', '2026-09-30');

    expect($period->days())->toBe(30);
    expect($period->to->format('H:i'))->toBe('23:59');
});

it('falls back to a year rather than failing on a bad date', function (): void {
    $period = ReportPeriod::fromInput('not-a-date', null, CarbonImmutable::parse('2026-09-24'));

    expect($period->from->toDateString())->toBe('2025-09-24');
    expect($period->to->toDateString())->toBe('2026-09-24');
});

/**
 * A chart that skipped a quiet month would compress a year into nine and lie
 * about the shape.
 */
it('names every month in the period, including the empty ones', function (): void {
    $months = ReportPeriod::fromInput('2026-01-15', '2026-04-02')->months();

    expect($months)->toBe(['2026-01', '2026-02', '2026-03', '2026-04']);
});

// --- recurring revenue ------------------------------------------------------

/**
 * A yearly service at 1200.00 is 100.00 a month, and the division happens in
 * integer minor units so no float touches the figure. A one-time line has no
 * monthly share, and counting it would put a setup fee in a recurring number.
 */
it('normalises recurring revenue to a month and skips one-time lines', function (): void {
    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Annually->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 120000,
    ]);

    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 2500,
    ]);

    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::OneTime->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 500000,
    ]);

    // Terminated is not revenue.
    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Terminated->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 999999,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Reports/Index')
            // 120000/12 + 2500 = 12500.
            ->where('revenue.mrr.0.minor', 12500)
            ->where('revenue.mrr.0.currency', 'EUR')
            // Twelve times the month, exactly.
            ->where('revenue.arr.0.minor', 150000));
});

it('keeps two currencies as two figures', function (): void {
    $lira = Customer::factory()->create(['currency_code' => 'TRY']);

    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 1000,
    ]);

    Service::factory()->create([
        'customer_id' => $lira->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'TRY',
        'recurring_minor' => 45000,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // Two rows, never one summed number: there is no rate here.
            ->has('revenue.mrr', 2));
});

/**
 * Movement is measured on services so the two numbers are comparable, and the
 * lost figure is what the terminated services used to bring in — which is the
 * number "we lost 400.00 a month" is made of.
 */
it('counts what was added and what was lost in the period', function (): void {
    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 3000,
        'starts_on' => CarbonImmutable::now()->subMonth()->toDateString(),
    ]);

    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Terminated->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 1000,
        'starts_on' => CarbonImmutable::now()->subYears(2)->toDateString(),
        'terminated_at' => CarbonImmutable::now()->subWeek(),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('revenue.added', 1)
            ->where('revenue.lost', 1)
            ->where('revenue.addedRecurring.0.minor', 3000)
            ->where('revenue.lostRecurring.0.minor', 1000));
});

// --- aging ------------------------------------------------------------------

/**
 * An invoice issued ninety days ago with sixty-day terms is thirty days overdue,
 * not ninety. A report that said otherwise would have somebody chasing a
 * customer who has done nothing wrong.
 */
it('ages an invoice from its due date, not its issue date', function (): void {
    Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => InvoiceStatus::Unpaid->value,
        'currency_code' => 'EUR',
        'total_minor' => 10000,
        'paid_minor' => 0,
        'issued_on' => CarbonImmutable::now()->subDays(90)->toDateString(),
        'due_on' => CarbonImmutable::now()->subDays(30)->toDateString(),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('aging.counts.1_30', 1)
            ->where('aging.counts.over_90', 0)
            ->where('aging.buckets.1_30.0.minor', 10000));
});

/**
 * A partially paid invoice ages by what is left on it, not by its total.
 */
it('ages an invoice by what is still outstanding', function (): void {
    Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => InvoiceStatus::PartiallyPaid->value,
        'currency_code' => 'EUR',
        'total_minor' => 10000,
        'paid_minor' => 6000,
        'due_on' => CarbonImmutable::now()->subDays(10)->toDateString(),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('aging.buckets.1_30.0.minor', 4000)
            ->where('aging.total.0.minor', 4000));
});

/**
 * Dropping it into "current" would hide it, and there is no honest arithmetic to
 * do with a missing date — the bucket exists so the data problem is visible.
 */
it('gives an invoice with no due date its own bucket', function (): void {
    Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => InvoiceStatus::Unpaid->value,
        'currency_code' => 'EUR',
        'total_minor' => 5000,
        'paid_minor' => 0,
        'due_on' => null,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('aging.counts.no_due_date', 1)
            ->where('aging.counts.current', 0));
});

it('leaves a paid invoice out of the aging entirely', function (): void {
    Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => InvoiceStatus::Paid->value,
        'currency_code' => 'EUR',
        'total_minor' => 10000,
        'paid_minor' => 10000,
        'due_on' => CarbonImmutable::now()->subDays(40)->toDateString(),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('aging.total', []));
});

// --- breakdowns -------------------------------------------------------------

/**
 * A gateway that took 1,000 and gave 400 back brought in 600, and a report
 * showing the gross would make a refund-heavy month look like a good one.
 */
it('reports what a gateway collected net of what it gave back', function (): void {
    $invoice = Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'currency_code' => 'EUR',
    ]);

    Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $this->customer->id,
        'gateway' => 'manual',
        'status' => PaymentStatus::Completed->value,
        'currency_code' => 'EUR',
        'amount_minor' => 100000,
        'refunded_minor' => 40000,
        'received_at' => CarbonImmutable::now()->subDays(3),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('breakdown.gateways.0.gateway', 'manual')
            ->where('breakdown.gateways.0.net.0.minor', 60000)
            ->where('breakdown.gateways.0.refunded.0.minor', 40000));
});

/**
 * A service with no product still recurs and still counts. Dropping it would
 * make the breakdown's total disagree with the MRR figure above it, and a report
 * that does not add up is a report nobody trusts.
 */
it('keeps a service with no product in the product breakdown', function (): void {
    $product = Product::factory()->create(['name' => 'Starter Hosting']);

    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'product_id' => $product->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 2000,
    ]);

    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'product_id' => null,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 500,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('breakdown.products', 2)
            // Most services first, and with one each the order is by whatever
            // the database gave; what matters is that both are there and the
            // unassigned one has no name.
            ->where('revenue.mrr.0.minor', 2500));
});

/**
 * A renewal invoice is for the term, so the figure is the whole amount rather
 * than a monthly share: this is what will actually be billed.
 */
it('reports renewals ahead at the full term amount', function (): void {
    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Annually->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 120000,
        'next_due_on' => CarbonImmutable::now()->addDays(10)->toDateString(),
    ]);

    // Outside every window.
    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Annually->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 999999,
        'next_due_on' => CarbonImmutable::now()->addDays(200)->toDateString(),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('renewals.0.days', 30)
            ->where('renewals.0.services', 1)
            // The whole term, not 10000 a month.
            ->where('renewals.0.value.0.minor', 120000)
            ->where('renewals.2.days', 90)
            ->where('renewals.2.services', 1));
});

/**
 * An invoice's date is when it was issued, not when it was paid. A revenue chart
 * built on issue dates is a chart of intent.
 */
it('draws money in from the ledger, with every month present', function (): void {
    Transaction::factory()->create([
        'customer_id' => $this->customer->id,
        'kind' => TransactionKind::Payment->value,
        'currency_code' => 'EUR',
        'amount_minor' => 25000,
        'occurred_at' => CarbonImmutable::now()->subMonths(2),
    ]);

    // A refund must not count as money in.
    Transaction::factory()->create([
        'customer_id' => $this->customer->id,
        'kind' => TransactionKind::Refund->value,
        'currency_code' => 'EUR',
        'amount_minor' => 99999,
        'occurred_at' => CarbonImmutable::now()->subMonths(2),
    ]);

    Service::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 1000,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('collected.currency', 'EUR')
            // Thirteen: twelve months back plus the current one, both ends
            // inclusive.
            ->has('collected.months', 13)
            ->where('collected.months.10.value', 25000));
});

// --- the boundary -----------------------------------------------------------

/**
 * The same screen answers the provider's question and a reseller's. A reseller
 * reading another reseller's revenue would be the worst possible leak.
 */
it('shows a reseller their own numbers and nobody else', function (): void {
    $context = app(OrganizationContext::class);

    $mine = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Anatolia Hosting',
        ownerName: 'Anatolia Owner',
        ownerEmail: 'owner@anatolia.test',
    ));

    $theirs = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Aegean Hosting',
        ownerName: 'Aegean Owner',
        ownerEmail: 'owner@aegean.test',
    ));

    // One service each, worth different amounts.
    foreach ([[$mine, 1000], [$theirs, 7000]] as [$reseller, $amount]) {
        $context->runAs($reseller['organization']->id, function () use ($reseller, $amount): void {
            $organization = Organization::query()->create([
                'parent_id' => $reseller['organization']->id,
                'type' => 'customer',
                'name' => $reseller['organization']->name.' Client',
                'slug' => Str::slug($reseller['organization']->name).'-c-'.Str::lower(Str::random(6)),
                'is_active' => true,
            ]);

            $customer = Customer::factory()->create([
                'organization_id' => $organization->id,
                'currency_code' => 'EUR',
            ]);

            Service::factory()->create([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'status' => ServiceStatus::Active->value,
                'billing_cycle' => BillingCycle::Monthly->value,
                'currency_code' => 'EUR',
                'recurring_minor' => $amount,
            ]);
        });
    }

    $operator = $mine['owner'];
    $operator->assignRole(SystemRole::Administrator);

    $this->actingAs($operator->fresh(), 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // Their own, and not the sum of both.
            ->where('revenue.mrr.0.minor', 1000)
            ->where('revenue.active', 1));
});

it('is shut to staff without the invoice permission', function (): void {
    $newcomer = StaffUser::factory()->create();

    expect($newcomer->effectivePermissions())->not->toContain('billing.invoices.view');

    $this->actingAs($newcomer, 'staff')
        ->get('/admin/reports')
        ->assertForbidden();
});

/**
 * More than one of everything on purpose: Laravel's strict mode only reports a
 * lazy load when a query returned more than one row.
 */
it('draws the whole report with several of everything', function (): void {
    $product = Product::factory()->create();

    Service::factory()->count(3)->create([
        'customer_id' => $this->customer->id,
        'product_id' => $product->id,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 1000,
        'next_due_on' => CarbonImmutable::now()->addDays(15)->toDateString(),
    ]);

    Invoice::factory()->count(2)->create([
        'customer_id' => $this->customer->id,
        'status' => InvoiceStatus::Overdue->value,
        'currency_code' => 'EUR',
        'total_minor' => 5000,
        'paid_minor' => 0,
        'due_on' => CarbonImmutable::now()->subDays(45)->toDateString(),
    ]);

    Transaction::factory()->count(2)->create([
        'customer_id' => $this->customer->id,
        'kind' => TransactionKind::Payment->value,
        'currency_code' => 'EUR',
        'amount_minor' => 1500,
        'occurred_at' => CarbonImmutable::now()->subDays(5),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('revenue.mrr.0.minor', 3000)
            ->where('aging.counts.31_60', 2)
            ->where('renewals.0.services', 3));
});
