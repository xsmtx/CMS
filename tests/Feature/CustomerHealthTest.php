<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Intelligence\CustomerHealth;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Intelligence\HealthSignal;
use App\Domain\Intelligence\LeakageKind;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\LeakageFinding;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Which customers somebody should look at (§21).
 *
 * **There is no score, and this file is what keeps it that way.** §21 asks
 * for customer health to be explainable, and the only honest way to be
 * explainable is not to compute the thing that would need explaining. Each
 * signal carries its own arithmetic; nothing is added to anything else.
 *
 * The other rule worth a test is that **a signal with nothing to say is
 * absent**. A wall of zeroes is a wall somebody stops reading, and the one
 * figure that is not zero disappears into it.
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

    // No `organization_id`: the factory gives a customer its own child
    // organization, which is what a real one has.
    $this->customer = Customer::factory()->create(['company_name' => 'Acme Ltd']);

    $this->health = app(CustomerHealth::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function overdueInvoiceFor(Customer $customer, int $total, int $paid, int $daysAgo): Invoice
{
    $invoice = Invoice::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::Unpaid,
        'currency_code' => 'EUR',
    ]);

    $invoice->forceFill([
        'total_minor' => $total,
        'paid_minor' => $paid,
        'due_on' => CarbonImmutable::now()->subDays($daysAgo),
    ])->save();

    return $invoice;
}

it('says nothing at all about a customer with nothing against them', function (): void {
    expect($this->health->for($this->customer->id))->toBe([]);
});

it('measures what is owed from the due date and by what is outstanding', function (): void {
    overdueInvoiceFor($this->customer, 10000, 4000, 30);

    $signals = $this->health->for($this->customer->id);

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->signal)->toBe(HealthSignal::Overdue)
        // The remainder, not the total: a partly paid invoice ages at what
        // is left of it.
        ->and($signals[0]->money?->minorFor('EUR'))->toBe(6000)
        ->and($signals[0]->days)->toBe(30);
});

it('leaves out an overdue invoice that has been paid off', function (): void {
    overdueInvoiceFor($this->customer, 10000, 10000, 30);

    expect($this->health->for($this->customer->id))->toBe([]);
});

it('counts a service that never came up', function (): void {
    Service::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Failed,
    ]);

    $signals = $this->health->for($this->customer->id);

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->signal)->toBe(HealthSignal::ServiceFailed)
        // Critical, because the customer is paying for something that never
        // arrived.
        ->and($signals[0]->signal->tone())->toBe('critical');
});

/**
 * A card is good until the end of its stated month, which is the thing
 * everybody gets wrong by a month.
 */
it('reads a card as good until the end of its stated month', function (): void {
    CarbonImmutable::setTestNow('2026-10-15 09:00:00');

    PaymentMethod::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'is_default' => true,
        'expiry_month' => 10,
        'expiry_year' => 2026,
    ]);

    $signals = $this->health->for($this->customer->id);

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->signal)->toBe(HealthSignal::CardExpiring)
        // The 31st, not the 1st.
        ->and($signals[0]->days)->toBe(16);
});

it('says nothing about a card that expires next year', function (): void {
    CarbonImmutable::setTestNow('2026-10-15 09:00:00');

    PaymentMethod::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'is_default' => true,
        'expiry_month' => 10,
        'expiry_year' => 2028,
    ]);

    expect($this->health->for($this->customer->id))->toBe([]);
});

/**
 * A customer with four stored cards is not in trouble because the one they
 * stopped using in 2023 has lapsed.
 */
it('looks only at the card the next renewal would be charged to', function (): void {
    CarbonImmutable::setTestNow('2026-10-15 09:00:00');

    PaymentMethod::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'is_default' => false,
        'expiry_month' => 1,
        'expiry_year' => 2024,
    ]);

    expect($this->health->for($this->customer->id))->toBe([]);
});

it('picks up revenue leakage against the customer', function (): void {
    LeakageFinding::factory()->of(LeakageKind::ServiceNotBilled)->create([
        'organization_id' => $this->provider->id,
        'customer_id' => $this->customer->id,
    ]);

    $signals = $this->health->for($this->customer->id);

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->signal)->toBe(HealthSignal::NotBilled)
        ->and($signals[0]->money?->minorFor('EUR'))->toBe(1990);
});

/**
 * Seven questions asked once for a page of customers rather than seven per
 * row. A `count()` inside a loop is not a lazy load, so nothing else would
 * have said a word about it.
 */
it('asks each question once for the whole page', function (): void {
    $second = Customer::factory()->create(['company_name' => 'Beta Ltd']);

    overdueInvoiceFor($this->customer, 5000, 0, 10);
    overdueInvoiceFor($second, 7000, 0, 3);

    $across = $this->health->across([$this->customer->id, $second->id]);

    expect($across)->toHaveKey($this->customer->id)
        ->and($across)->toHaveKey($second->id)
        ->and($across[$this->customer->id][0]->money?->minorFor('EUR'))->toBe(5000)
        ->and($across[$second->id][0]->money?->minorFor('EUR'))->toBe(7000);
});

it('keeps two signals apart rather than adding them up', function (): void {
    overdueInvoiceFor($this->customer, 5000, 0, 10);

    Service::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'status' => ServiceStatus::Failed,
    ]);

    $signals = $this->health->for($this->customer->id);

    // Two facts, not a three. There is no total anywhere in this family.
    expect($signals)->toHaveCount(2)
        ->and(array_map(static fn ($s) => $s->signal, $signals))
        ->toContain(HealthSignal::Overdue, HealthSignal::ServiceFailed);
});

it('lists only customers with something to say, worst first', function (): void {
    $quiet = Customer::factory()->create(['company_name' => 'Quiet Ltd']);
    $failing = Customer::factory()->create(['company_name' => 'Zeta Ltd']);

    overdueInvoiceFor($this->customer, 5000, 0, 10);

    Service::factory()->create([
        'organization_id' => $failing->organization_id,
        'customer_id' => $failing->id,
        'status' => ServiceStatus::Failed,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/customer-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Intelligence/CustomerHealth')
            ->has('rows', 2)
            // Critical before warning, whatever the names are.
            ->where('rows.0.name', 'Zeta Ltd')
            ->where('rows.0.worst', 'critical')
            ->where('rows.1.name', 'Acme Ltd')
            ->where('rows.1.worst', 'warning'));

    expect($quiet->fresh())->not->toBeNull();
});

it('sends no score of any kind', function (): void {
    overdueInvoiceFor($this->customer, 5000, 0, 10);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/customer-health')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $row = $page->toArray()['props']['rows'][0];

            // The guard on the promise: a key called anything like a score
            // is the thing §21 asked this family not to have.
            foreach (array_keys($row) as $key) {
                expect($key)->not->toContain('score')
                    ->and($key)->not->toContain('rating')
                    ->and($key)->not->toContain('total');
            }
        });
});

/**
 * `useTranslations()` has no `trans_choice`, so a count worded in the browser
 * reads as “1 invoices”. Every sentence with a number in it is built on the
 * server for that reason, and this is what says so.
 */
it('words a count of one as one', function (): void {
    overdueInvoiceFor($this->customer, 5000, 0, 10);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/intelligence/customer-health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.signals.0.figure', '1 invoice, oldest 10 days overdue')
            ->where('examined', 'Examined 1 customer.'));
});

it('refuses the screen to somebody who may not see customers', function (): void {
    $nobody = StaffUser::factory()->create();

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/intelligence/customer-health')
        ->assertForbidden();
});
