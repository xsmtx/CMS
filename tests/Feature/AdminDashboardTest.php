<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Provisioning\Models\Service;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

/**
 * The screen that had no test for twelve phases, and was therefore still
 * showing Phase 0's placeholder.
 *
 * Two rules are under test throughout, because they are what makes a
 * dashboard worth opening:
 *
 * - **A row appears only when it is not zero.** A list padded with zeroes is
 *   a list an operator skims, and then the one row that mattered is skimmed
 *   with it.
 * - **A block is absent when the operator may not see it**, not empty. A
 *   support agent gets tickets and no revenue.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

it('says nothing needs attention when nothing does', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Dashboard')
            ->has('attention', 0)
            ->has('headline')
            ->has('revenue'));
});

it('lists what is actually wrong, and nothing that is not', function (): void {
    Invoice::factory()->count(3)->create(['status' => InvoiceStatus::Overdue->value]);
    Invoice::factory()->create(['status' => InvoiceStatus::Paid->value]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // One row, because one thing is wrong. Not seven rows of which
            // six say zero.
            ->has('attention', 1)
            ->where('attention.0.key', 'invoices.overdue')
            ->where('attention.0.count', 3)
            ->where('attention.0.tone', 'danger'));
});

/**
 * Money is integer minor units everywhere, including here. A yearly service
 * at 1200.00 is 100.00 a month, and the division happens in minor units so
 * no float touches the figure.
 */
it('normalises recurring revenue to a month', function (): void {
    $currency = strtoupper((string) config('platform.crm.default_currency', 'TRY'));

    Service::factory()->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Annually->value,
        'currency_code' => $currency,
        'recurring_minor' => 120000,
    ]);

    Service::factory()->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => $currency,
        'recurring_minor' => 2500,
    ]);

    // Terminated services are not revenue.
    Service::factory()->create([
        'status' => ServiceStatus::Terminated->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => $currency,
        'recurring_minor' => 999999,
    ]);

    // Neither is a one-time line: it does not recur, so it has no monthly
    // share to take.
    Service::factory()->create([
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::OneTime->value,
        'currency_code' => $currency,
        'recurring_minor' => 500000,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('headline', function (Collection $figures): bool {
                $mrr = $figures->firstWhere('key', 'mrr');

                // 120000/12 + 2500 = 12500 minor units.
                return $mrr !== null && str_contains((string) $mrr['value'], '125');
            }));
});

it('draws twelve months even when most of them are empty', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // A chart that skipped a quiet month would compress a year into
            // nine and lie about the shape.
            ->has('revenue.months', 12));
});

it('leaves out the blocks an operator may not see', function (): void {
    $support = StaffUser::factory()->create();
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // Absent, not empty: a number computed and then hidden is a
            // number that still cost a query.
            ->where('revenue', null)
            ->where('headline', fn (Collection $figures): bool => $figures
                ->pluck('key')
                ->doesntContain('mrr'))
            // Support *does* hold `platform.audit.view` by design — an agent
            // needs to see who suspended the service the customer is asking
            // about — so the trail is present. Asserted rather than assumed,
            // because "absent" and "the role happens to hold it" are two
            // different reasons for the same shape.
            ->has('activity'));
});

it('shows the owner what just happened', function (): void {
    // The seeding above writes audit rows of its own, so the trail is not
    // empty by the time this asks.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('activity'));
});
