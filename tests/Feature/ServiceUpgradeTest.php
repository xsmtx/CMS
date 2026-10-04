<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Billing\RecordPayment;
use App\Application\Billing\RecordPaymentRequest;
use App\Application\Provisioning\ApplyUpgrade;
use App\Application\Provisioning\Exceptions\UpgradeRefused;
use App\Application\Provisioning\PriceUpgrade;
use App\Application\Provisioning\RequestUpgrade;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Provisioning\UpgradeState;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceUpgrade;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * Moving a service between plans, with a prorated figure
 * (`whmcs-parity-plan.md` §2.1).
 *
 * `changePackage()` has existed since Phase 6 and nothing called it on a
 * service, so an account could be created and terminated and never moved.
 * What earns the tests is the arithmetic: the two amounts an invoice shows
 * have to sum to the figure charged, a cycle change restarts the term and a
 * same-cycle change does not, and a downgrade is a credit rather than a
 * refund.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->admin = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->customer = Customer::factory()->create();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A plan with one price, in one currency.
 */
function plan(string $name, int $minor, BillingCycle $cycle = BillingCycle::Monthly): Product
{
    $product = Product::factory()->create([
        'organization_id' => test()->provider->id,
        'name' => $name,
        'status' => 'active',
    ]);

    ProductPrice::query()->create([
        'organization_id' => test()->provider->id,
        'product_id' => $product->id,
        'billing_cycle' => $cycle->value,
        'currency_code' => 'EUR',
        'recurring_minor' => $minor,
        'setup_minor' => 0,
    ]);

    return $product->fresh(['prices']);
}

function upgradableService(Product $product, int $minor, string $dueOn): Service
{
    return Service::factory()->create([
        'organization_id' => test()->provider->id,
        'customer_id' => test()->customer->id,
        'product_id' => $product->id,
        'name' => $product->name,
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => $minor,
        'setup_minor' => 0,
        'module' => 'manual',
        'next_due_on' => $dueOn,
    ]);
}

/**
 * The arithmetic, which is the whole reason this family needs tests.
 */
it('prorates both halves over the days left, and their difference is the total', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $starter = plan('Starter', 10_00);
    $business = plan('Business', 30_00);

    // A term from 20 September to 20 October: 30 days, 16 of them left.
    $service = upgradableService($starter, 10_00, '2026-10-20');

    $change = app(PriceUpgrade::class)->handle($service, $business, BillingCycle::Monthly);

    expect($change->termDays)->toBe(30)
        ->and($change->daysRemaining)->toBe(16)
        // 1000 × 16 / 30 = 533, and 3000 × 16 / 30 = 1600.
        ->and($change->credit->minorUnits)->toBe(533)
        ->and($change->charge->minorUnits)->toBe(1600)
        ->and($change->difference()->minorUnits)->toBe(1067)
        ->and($change->restartsTerm)->toBeFalse();
});

/**
 * Monthly to annual is a new year beginning today, not twelve months of
 * difference.
 */
it('charges a whole new term when the cycle changes', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $starter = plan('Starter', 10_00);
    $yearly = plan('Starter yearly', 100_00, BillingCycle::Annually);

    $service = upgradableService($starter, 10_00, '2026-10-20');

    $change = app(PriceUpgrade::class)->handle($service, $yearly, BillingCycle::Annually);

    // Prorating the annual price over sixteen days would hand somebody a year
    // of hosting for five euros.
    expect($change->charge->minorUnits)->toBe(10000)
        ->and($change->credit->minorUnits)->toBe(533)
        ->and($change->restartsTerm)->toBeTrue();
});

it('refuses a plan that is not sold in this service’s currency', function (): void {
    $starter = plan('Starter', 10_00);

    $dollars = Product::factory()->create([
        'organization_id' => $this->provider->id,
        'name' => 'Business USD',
        'status' => 'active',
    ]);

    ProductPrice::query()->create([
        'organization_id' => $this->provider->id,
        'product_id' => $dollars->id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'USD',
        'recurring_minor' => 30_00,
        'setup_minor' => 0,
    ]);

    $service = upgradableService($starter, 10_00, '2026-12-01');

    // There is no exchange rate anywhere in this product, and inventing one
    // here would be the first.
    expect(fn () => app(PriceUpgrade::class)
        ->handle($service, $dollars->fresh(['prices']), BillingCycle::Monthly))
        ->toThrow(UpgradeRefused::class);
});

it('raises an invoice whose two lines sum to what is charged', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $starter = plan('Starter', 10_00);
    $business = plan('Business', 30_00);
    $service = upgradableService($starter, 10_00, '2026-10-20');

    $upgrade = app(RequestUpgrade::class)
        ->handle($service, $business, BillingCycle::Monthly, $this->admin);

    $invoice = Invoice::query()->findOrFail($upgrade->invoice_id);

    expect($upgrade->state)->toBe(UpgradeState::AwaitingPayment)
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid)
        ->and($invoice->items)->toHaveCount(2)
        // The credit is a negative line, so the lines add to the total by
        // construction rather than by arithmetic nobody checked.
        ->and($invoice->items->sum('line_amount_minor'))->toBe($upgrade->difference_minor);
});

/**
 * A prorated line is not a term being bought, and advancing the renewal date
 * on it would give the customer the remainder of their old term twice.
 */
it('carries no period on its lines, so the renewal date is not advanced', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $service = upgradableService(plan('Starter', 10_00), 10_00, '2026-10-20');

    $upgrade = app(RequestUpgrade::class)
        ->handle($service, plan('Business', 30_00), BillingCycle::Monthly, $this->admin);

    $invoice = Invoice::query()->findOrFail($upgrade->invoice_id);

    foreach ($invoice->items as $item) {
        expect($item->period_end)->toBeNull();
    }
});

it('moves the account only once the difference has been paid', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $service = upgradableService(plan('Starter', 10_00), 10_00, '2026-10-20');
    $business = plan('Business', 30_00);

    $upgrade = app(RequestUpgrade::class)
        ->handle($service, $business, BillingCycle::Monthly, $this->admin);

    expect($service->fresh()->product_id)->not->toBe($business->id);

    $invoice = Invoice::query()->findOrFail($upgrade->invoice_id);

    app(RecordPayment::class)->handle(
        $invoice,
        new RecordPaymentRequest(Money::ofMinor($invoice->total_minor, 'EUR')),
        $this->admin,
    );

    $moved = $service->fresh();

    expect($upgrade->fresh()->state)->toBe(UpgradeState::Completed)
        ->and($moved->product_id)->toBe($business->id)
        // The catalogue price, never the prorated figure: writing the part
        // month onto the service would bill it for ever.
        ->and($moved->recurring_minor)->toBe(3000)
        // Same cycle, so the renewal date does not move.
        ->and($moved->next_due_on?->toDateString())->toBe('2026-10-20');
});

it('starts a new term when the cycle changed', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $service = upgradableService(plan('Starter', 10_00), 10_00, '2026-10-20');
    $yearly = plan('Starter yearly', 100_00, BillingCycle::Annually);

    $upgrade = app(RequestUpgrade::class)
        ->handle($service, $yearly, BillingCycle::Annually, $this->admin);

    $invoice = Invoice::query()->findOrFail($upgrade->invoice_id);

    app(RecordPayment::class)->handle(
        $invoice,
        new RecordPaymentRequest(Money::ofMinor($invoice->total_minor, 'EUR')),
        $this->admin,
    );

    $moved = $service->fresh();

    expect($moved->billing_cycle)->toBe(BillingCycle::Annually)
        ->and($moved->next_due_on?->toDateString())->toBe('2027-10-04')
        // Cleared rather than moved: a new term has been invoiced for none of
        // it, and the old value is how far the *old* cycle had got.
        ->and($moved->renewal_invoiced_through)->toBeNull();
});

/**
 * A downgrade is credited, never refunded.
 */
it('writes account credit rather than raising an invoice for a cheaper plan', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $service = upgradableService(plan('Business', 30_00), 30_00, '2026-10-20');
    $starter = plan('Starter', 10_00);

    $upgrade = app(RequestUpgrade::class)
        ->handle($service, $starter, BillingCycle::Monthly, $this->admin);

    // Nothing to pay, so nothing to wait for.
    expect($upgrade->state)->toBe(UpgradeState::Authorized)
        ->and($upgrade->invoice_id)->toBeNull()
        ->and($upgrade->isDowngrade())->toBeTrue();

    app(ApplyUpgrade::class)->handle($upgrade, $this->admin);

    expect($upgrade->fresh()->state)->toBe(UpgradeState::Completed)
        ->and($service->fresh()->product_id)->toBe($starter->id);
});

it('refuses a second request while one is open', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $service = upgradableService(plan('Starter', 10_00), 10_00, '2026-10-20');

    app(RequestUpgrade::class)->handle($service, plan('Business', 30_00), BillingCycle::Monthly, $this->admin);

    // Two would race each other to the provider, and the second was priced
    // against a term the first is about to change.
    expect(fn () => app(RequestUpgrade::class)
        ->handle($service->fresh(), plan('Pro', 50_00), BillingCycle::Monthly, $this->admin))
        ->toThrow(UpgradeRefused::class);
});

it('refuses to move a service that is not active', function (): void {
    $service = upgradableService(plan('Starter', 10_00), 10_00, '2026-12-01');
    $service->forceFill(['status' => ServiceStatus::Suspended->value])->save();

    expect(fn () => app(RequestUpgrade::class)
        ->handle($service->fresh(), plan('Business', 30_00), BillingCycle::Monthly, $this->admin))
        ->toThrow(UpgradeRefused::class);
});

it('refuses the plan the service is already on', function (): void {
    $starter = plan('Starter', 10_00);
    $service = upgradableService($starter, 10_00, '2026-12-01');

    expect(fn () => app(RequestUpgrade::class)
        ->handle($service, $starter, BillingCycle::Monthly, $this->admin))
        ->toThrow(UpgradeRefused::class);
});

it('drives the preview and the request from the service screen', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $service = upgradableService(plan('Starter', 10_00), 10_00, '2026-10-20');
    $business = plan('Business', 30_00);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/services/'.$service->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Services/Show')
            ->where('can.upgrade', true)
            ->has('upgrade.targets'));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/services/'.$service->id.'/upgrade/preview', [
            'product' => $business->id,
            'cycle' => 'monthly',
        ])
        ->assertRedirect()
        ->assertSessionHas('upgradePreview');

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/services/'.$service->id.'/upgrade', [
            'product' => $business->id,
            'cycle' => 'monthly',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ServiceUpgrade::query()->count())->toBe(1);
});

it('drives the queue and withdraws a request', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $service = upgradableService(plan('Starter', 10_00), 10_00, '2026-10-20');

    $upgrade = app(RequestUpgrade::class)
        ->handle($service, plan('Business', 30_00), BillingCycle::Monthly, $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/services/upgrades')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Services/Upgrades')
            ->has('upgrades.data', 1));

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/services/upgrades/'.$upgrade->id)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($upgrade->fresh()->state)->toBe(UpgradeState::Cancelled);
});

it('refuses the queue to somebody without the permission', function (): void {
    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($nobody->fresh(), 'staff')
        ->get('/admin/services/upgrades')
        ->assertForbidden();
});
