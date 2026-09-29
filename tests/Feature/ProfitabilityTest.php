<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Intelligence\Profitability;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Intelligence\CostScope;
use App\Domain\Intelligence\ProfitGrouping;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\CostEntry;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * What a month earned and what it cost to earn it (§21).
 *
 * **The rule this file exists to protect is that a margin is not always a
 * number.** There is no rate anywhere in this product, so a customer earning
 * euros on a server costing lira has a revenue, a cost and no margin —
 * printing the revenue as though the cost were zero would be the most
 * misleading figure this product could produce.
 *
 * The other half is that revenue comes from the **services**, never from
 * invoice lines: a line copies its description (ADR 0021), so grouping by
 * one merges two products renamed the same thing.
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

    $this->month = CarbonImmutable::parse('2026-10-01');
    $this->report = app(Profitability::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function anEarningService(
    Customer $customer,
    ?Server $server,
    int $minor,
    string $currency = 'EUR',
    BillingCycle $cycle = BillingCycle::Monthly,
): Service {
    return Service::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'server_id' => $server?->id,
        'name' => 'hosting',
        'status' => ServiceStatus::Active,
        'currency_code' => $currency,
        'recurring_minor' => $minor,
        'billing_cycle' => $cycle,
    ]);
}

function profitServer(Organization $provider, string $name = 'web-7'): Server
{
    return Server::factory()->create(['organization_id' => $provider->id, 'name' => $name]);
}

it('takes revenue from the service and cost from the allocation', function (): void {
    $server = profitServer($this->provider);
    anEarningService($this->customer, $server, 5000);

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(2000)
        ->create(['organization_id' => $this->provider->id]);

    $report = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Customer);

    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows'][0]->label)->toBe('Acme Ltd')
        ->and($report['rows'][0]->revenue->minorFor('EUR'))->toBe(5000)
        ->and($report['rows'][0]->cost->minorFor('EUR'))->toBe(2000)
        ->and($report['rows'][0]->margin()?->minorFor('EUR'))->toBe(3000);
});

/**
 * MRR has meant integer division by the cycle's own length since the reports
 * were written, and a yearly price is not a monthly one.
 */
it('divides a yearly price down to a month', function (): void {
    anEarningService($this->customer, null, 120000, cycle: BillingCycle::Annually);

    $report = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Customer);

    expect($report['revenue']->minorFor('EUR'))->toBe(10000);
});

/**
 * A setup fee inside a monthly figure overstates every month after the
 * first, which is why a one-time price is skipped rather than counted.
 */
it('skips a one-time price rather than counting it as zero', function (): void {
    anEarningService($this->customer, null, 9900, cycle: BillingCycle::OneTime);

    $report = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Customer);

    expect($report['revenue']->currencies())->toBe([])
        // The service is still there and still counted as a service.
        ->and($report['rows'][0]->services)->toBe(1);
});

/**
 * The figure this whole screen exists to refuse. Both numbers beside it are
 * true; the difference between them is not a number.
 */
it('states no margin where a cost is in a currency nothing earns', function (): void {
    $server = profitServer($this->provider);
    anEarningService($this->customer, $server, 5000, 'EUR');

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(450000, 'TRY')
        ->create(['organization_id' => $this->provider->id]);

    $row = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Customer)['rows'][0];

    expect($row->isMixedCurrency())->toBeTrue()
        ->and($row->margin())->toBeNull()
        // And both halves are still reported, because both are true.
        ->and($row->revenue->minorFor('EUR'))->toBe(5000)
        ->and($row->cost->minorFor('TRY'))->toBe(450000);
});

/**
 * A currency with revenue and no cost is not mixed: it means nobody recorded
 * a cost against it, which is true and is what the figure says.
 */
it('states a margin where a currency earns and nothing costs', function (): void {
    anEarningService($this->customer, null, 5000, 'EUR');
    anEarningService($this->customer, null, 20000, 'TRY');

    CostEntry::factory()
        ->costing(1000, 'EUR')
        ->create(['organization_id' => $this->provider->id]);

    $row = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Customer)['rows'][0];

    // Both services are this customer's, so the row carries the whole
    // installation cost: 500 landed on each and both are in this row.
    expect($row->isMixedCurrency())->toBeFalse()
        ->and($row->margin()?->minorFor('EUR'))->toBe(4000)
        ->and($row->margin()?->minorFor('TRY'))->toBe(20000);
});

/**
 * An empty server belongs to no customer and no product. Dropping it would
 * understate the month, which is the one direction a cost report must never
 * be wrong in.
 */
it('keeps an unallocated cost out of every row and inside the total', function (): void {
    anEarningService($this->customer, null, 5000);

    $empty = profitServer($this->provider, 'web-9');

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $empty->id)
        ->costing(20000)
        ->create(['organization_id' => $this->provider->id]);

    $report = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Customer);

    expect($report['rows'][0]->cost->currencies())->toBe([])
        ->and($report['unallocated']->minorFor('EUR'))->toBe(20000)
        ->and($report['cost']->minorFor('EUR'))->toBe(20000);
});

it('groups by server as well as by customer', function (): void {
    $a = profitServer($this->provider, 'web-1');
    $b = profitServer($this->provider, 'web-2');

    anEarningService($this->customer, $a, 5000);
    anEarningService($this->customer, $b, 3000);

    $byServer = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Server);
    $byCustomer = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Customer);

    expect($byServer['rows'])->toHaveCount(2)
        // One customer, two servers: the same money, asked two ways.
        ->and($byCustomer['rows'])->toHaveCount(1)
        ->and($byCustomer['rows'][0]->revenue->minorFor('EUR'))->toBe(8000);
});

it('leaves a terminated service out of the revenue', function (): void {
    $gone = anEarningService($this->customer, null, 5000);
    $gone->forceFill(['status' => ServiceStatus::Terminated])->save();

    $report = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Customer);

    expect($report['rows'])->toBe([])
        ->and($report['revenue']->currencies())->toBe([]);
});

/**
 * A suspended account earns nothing this month and still occupies the
 * machine — so it is out of the revenue and inside the cost allocation.
 * That asymmetry is deliberate and is the whole reason the two sets of
 * statuses differ.
 */
it('leaves a suspended service out of the revenue and inside the cost', function (): void {
    $server = profitServer($this->provider);
    $active = anEarningService($this->customer, $server, 5000);
    $suspended = anEarningService($this->customer, $server, 5000);
    $suspended->forceFill(['status' => ServiceStatus::Suspended])->save();

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(10000)
        ->create(['organization_id' => $this->provider->id]);

    $report = $this->report->forMonth($this->provider->id, $this->month, ProfitGrouping::Customer);

    expect($report['revenue']->minorFor('EUR'))->toBe(5000)
        // Half the cost went to the suspended account, which is not in any
        // row — so the active service carries only its own half.
        ->and($report['rows'][0]->cost->minorFor('EUR'))->toBe(5000)
        ->and($active->fresh()?->status)->toBe(ServiceStatus::Active);
});

it('drives the screen', function (): void {
    $server = profitServer($this->provider);
    anEarningService($this->customer, $server, 5000);

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(2000)
        ->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/profitability')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Intelligence/Profitability')
            ->has('rows', 1)
            ->where('rows.0.label', 'Acme Ltd')
            ->has('rows.0.margin', 1)
            ->where('rows.0.mixedCurrency', false)
            ->where('grouping', 'customer'));
});

it('sends no margin at all for a mixed row', function (): void {
    $server = profitServer($this->provider);
    anEarningService($this->customer, $server, 5000, 'EUR');

    CostEntry::factory()
        ->against(CostScope::Server, Server::class, $server->id)
        ->costing(450000, 'TRY')
        ->create(['organization_id' => $this->provider->id]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/profitability')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.margin', null)
            ->where('rows.0.mixedCurrency', true));
});

/**
 * Somebody looking at July is usually about to send July to a colleague.
 */
it('takes the month and the grouping from the address bar', function (): void {
    anEarningService($this->customer, null, 5000);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/profitability?month=2026-07-01&by=server')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('month', '2026-07-01')
            ->where('grouping', 'server'));
});

/** They edited the URL; the useful answer is the month they are standing in. */
it('falls back to this month rather than refusing a bad one', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/profitability?month=nonsense&by=nonsense')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('month', CarbonImmutable::now()->startOfMonth()->toDateString())
            ->where('grouping', 'customer'));
});

it('refuses the report to somebody without the commercial permission', function (): void {
    $agent = StaffUser::factory()->create();
    $agent->assignRole(SystemRole::Support);

    $this->actingAs($agent->fresh(), 'staff')
        ->get('/admin/intelligence/profitability')
        ->assertForbidden();
});
