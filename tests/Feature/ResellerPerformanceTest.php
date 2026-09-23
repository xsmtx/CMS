<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\ResellerAttributes;
use App\Application\Resellers\RecordResellerEntry;
use App\Application\Resellers\ResellerLedger;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Resellers\ResellerLedgerKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * What the provider sold through its resellers.
 *
 * The attribution is the interesting part: a customer's rows belong to its
 * parent, and that works **because there is one level of resale**. A reseller
 * owns customers and nothing else, so the organization that owns an order is a
 * customer and its `parent_id` is the seller — attribution is a join rather
 * than a recursive walk of `path`.
 *
 * Everything else is the platform's usual arithmetic: money grouped by
 * currency and never summed, recurring revenue normalised to a month in
 * integer minor units, and a draft invoice counted as nothing because it is
 * not a document yet.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = StaffUser::factory()->create();
    $this->provider->assignRole(SystemRole::Administrator);
    $this->provider = $this->provider->fresh();
});

/** A reseller, and a customer of theirs. */
function resellerWithCustomer(string $name, string $email): array
{
    $created = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: $name,
        ownerName: $name.' Owner',
        ownerEmail: $email,
    ));

    $reseller = $created['organization'];
    $context = app(OrganizationContext::class);

    $customer = $context->runAs($reseller->id, function () use ($reseller): Customer {
        $organization = Organization::query()->create([
            'parent_id' => $reseller->id,
            'type' => 'customer',
            'name' => $reseller->name.' Client',
            'slug' => Str::slug($reseller->name).'-client-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        return Customer::factory()->create([
            'organization_id' => $organization->id,
            'currency_code' => 'EUR',
        ]);
    });

    return ['reseller' => $reseller, 'customer' => $customer];
}

it('attributes a customer rows to the reseller that sold to them', function (): void {
    $mine = resellerWithCustomer('Anatolia Hosting', 'owner@anatolia.test');
    $theirs = resellerWithCustomer('Aegean Hosting', 'owner@aegean.test');

    $context = app(OrganizationContext::class);

    $context->runAs($mine['reseller']->id, function () use ($mine): void {
        Order::factory()->count(2)->create(['customer_id' => $mine['customer']->id]);
    });

    $context->runAs($theirs['reseller']->id, function () use ($theirs): void {
        Order::factory()->create(['customer_id' => $theirs['customer']->id]);
    });

    $this->actingAs($this->provider, 'staff')
        ->get('/admin/reports/resellers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Resellers/Report')
            ->has('rows', 2)
            // Ordered by name, so Aegean is first.
            ->where('rows.0.name', 'Aegean Hosting')
            ->where('rows.0.orders', 1)
            ->where('rows.0.customers', 1)
            ->where('rows.1.name', 'Anatolia Hosting')
            ->where('rows.1.orders', 2));
});

/**
 * A yearly service at 1200.00 is 100.00 a month, and the division happens in
 * minor units so no float touches the figure. A one-time line has no monthly
 * share, so it is skipped rather than counted as zero.
 */
it('normalises recurring revenue to a month, in minor units', function (): void {
    $mine = resellerWithCustomer('Anatolia Hosting', 'owner@anatolia.test');

    app(OrganizationContext::class)->runAs($mine['reseller']->id, function () use ($mine): void {
        Service::factory()->create([
            'customer_id' => $mine['customer']->id,
            'status' => ServiceStatus::Active->value,
            'billing_cycle' => BillingCycle::Annually->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 120000,
        ]);

        Service::factory()->create([
            'customer_id' => $mine['customer']->id,
            'status' => ServiceStatus::Active->value,
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 2500,
        ]);

        // Terminated is not revenue.
        Service::factory()->create([
            'customer_id' => $mine['customer']->id,
            'status' => ServiceStatus::Terminated->value,
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 999999,
        ]);

        // A one-time line does not recur, so it has no monthly share to take.
        Service::factory()->create([
            'customer_id' => $mine['customer']->id,
            'status' => ServiceStatus::Active->value,
            'billing_cycle' => BillingCycle::OneTime->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 500000,
        ]);
    });

    $this->actingAs($this->provider, 'staff')
        ->get('/admin/reports/resellers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // 120000/12 + 2500 = 12500.
            ->where('rows.0.recurring.0.minor', 12500)
            ->where('rows.0.recurring.0.currency', 'EUR')
            ->where('rows.0.services', 3));
});

it('keeps two currencies as two figures', function (): void {
    $mine = resellerWithCustomer('Anatolia Hosting', 'owner@anatolia.test');

    app(OrganizationContext::class)->runAs($mine['reseller']->id, function () use ($mine): void {
        Service::factory()->create([
            'customer_id' => $mine['customer']->id,
            'status' => ServiceStatus::Active->value,
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'EUR',
            'recurring_minor' => 1000,
        ]);

        Service::factory()->create([
            'customer_id' => $mine['customer']->id,
            'status' => ServiceStatus::Active->value,
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency_code' => 'TRY',
            'recurring_minor' => 45000,
        ]);
    });

    $this->actingAs($this->provider, 'staff')
        ->get('/admin/reports/resellers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // Two rows, never one summed number: there is no rate here to
            // turn lira into euros with.
            ->has('rows.0.recurring', 2));
});

it('counts issued invoices and not drafts', function (): void {
    $mine = resellerWithCustomer('Anatolia Hosting', 'owner@anatolia.test');

    app(OrganizationContext::class)->runAs($mine['reseller']->id, function () use ($mine): void {
        Invoice::factory()->create([
            'customer_id' => $mine['customer']->id,
            'status' => InvoiceStatus::Unpaid->value,
            'currency_code' => 'EUR',
            'total_minor' => 4500,
            'issued_on' => CarbonImmutable::now()->toDateString(),
        ]);

        // Not a document yet (ADR 0023), so not something that was invoiced.
        Invoice::factory()->create([
            'customer_id' => $mine['customer']->id,
            'status' => InvoiceStatus::Draft->value,
            'currency_code' => 'EUR',
            'total_minor' => 99900,
            'issued_on' => CarbonImmutable::now()->toDateString(),
        ]);
    });

    $this->actingAs($this->provider, 'staff')
        ->get('/admin/reports/resellers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('rows.0.invoiced.0.minor', 4500));
});

it('leaves out what happened outside the period', function (): void {
    $mine = resellerWithCustomer('Anatolia Hosting', 'owner@anatolia.test');

    app(OrganizationContext::class)->runAs($mine['reseller']->id, function () use ($mine): void {
        Order::factory()->create([
            'customer_id' => $mine['customer']->id,
            'created_at' => CarbonImmutable::now()->subYears(3),
        ]);
    });

    $this->actingAs($this->provider, 'staff');

    // The default window is the last twelve months.
    $this->get('/admin/reports/resellers')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('rows.0.orders', 0));

    $from = CarbonImmutable::now()->subYears(4)->toDateString();
    $to = CarbonImmutable::now()->toDateString();

    $this->get("/admin/reports/resellers?from={$from}&to={$to}")
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('rows.0.orders', 1));
});

/**
 * Somebody who typed the dates the other way round meant the period between
 * them, and a report is not the place to refuse them over it.
 */
it('reads a backwards period the way it was meant', function (): void {
    resellerWithCustomer('Anatolia Hosting', 'owner@anatolia.test');

    $from = CarbonImmutable::now()->toDateString();
    $to = CarbonImmutable::now()->subMonths(6)->toDateString();

    $this->actingAs($this->provider, 'staff')
        ->get("/admin/reports/resellers?from={$from}&to={$to}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('period.from', $to)
            ->where('period.to', $from));
});

it('falls back to the default window rather than failing on a bad date', function (): void {
    resellerWithCustomer('Anatolia Hosting', 'owner@anatolia.test');

    $this->actingAs($this->provider, 'staff')
        ->get('/admin/reports/resellers?from=not-a-date')
        ->assertOk();
});

it('shows the balance beside what they sold', function (): void {
    $mine = resellerWithCustomer('Anatolia Hosting', 'owner@anatolia.test');

    app(ResellerLedger::class)->record(new RecordResellerEntry(
        organizationId: $mine['reseller']->id,
        kind: ResellerLedgerKind::Payment,
        amount: Money::ofMinor(75000, 'EUR'),
    ));

    $this->actingAs($this->provider, 'staff')
        ->get('/admin/reports/resellers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('rows.0.balances.0.minor', 75000));
});

/**
 * The one disclosure the whole boundary exists to prevent: a reseller reading
 * a row about the reseller beside them.
 */
it('is shut to a reseller, administrator or not', function (): void {
    $mine = resellerWithCustomer('Anatolia Hosting', 'owner@anatolia.test');
    resellerWithCustomer('Aegean Hosting', 'owner@aegean.test');

    $owner = StaffUser::query()
        ->withoutGlobalScope('organization')
        ->where('email', 'owner@anatolia.test')
        ->sole();

    $owner->assignRole(SystemRole::Administrator);

    $this->actingAs($owner->fresh(), 'staff')
        ->get('/admin/reports/resellers')
        ->assertForbidden();

    expect($mine['reseller']->type->value)->toBe('reseller');
});

it('says so when there are no resellers rather than drawing an empty table', function (): void {
    $this->actingAs($this->provider, 'staff')
        ->get('/admin/reports/resellers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('rows', []));
});
