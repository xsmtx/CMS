<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Automation\Runs\GenerateRenewalInvoices;
use App\Application\Billing\AddBillableItem;
use App\Application\Billing\Exceptions\BillableItemRefused;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\BillableItem;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;

/**
 * One-off charges waiting for the next invoice
 * (`whmcs-parity-plan.md` §2.2).
 *
 * This product had no way to put anything on a future invoice at all. What
 * earns the tests is the shape `usage_snapshots` already has: a charge is
 * **quoted** by a line and stamped with it, so a run that is retried cannot
 * charge it twice — and a customer whose only due charge is a one-off still
 * gets an invoice.
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

    $this->customer = Customer::factory()->create(['currency_code' => 'EUR']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function aCharge(int $minor = 75_00, int $quantity = 1, ?string $chargeOn = null): BillableItem
{
    return app(AddBillableItem::class)->handle(
        customer: test()->customer,
        description: 'Migration, one hour',
        unitPrice: Money::ofMinor($minor, 'EUR'),
        quantity: $quantity,
        chargeOn: $chargeOn === null ? null : CarbonImmutable::parse($chargeOn),
        actor: test()->admin,
    );
}

function renewingService(string $dueOn): Service
{
    return Service::factory()->create([
        'organization_id' => test()->customer->organization_id,
        'customer_id' => test()->customer->id,
        'name' => 'Hosting',
        'status' => ServiceStatus::Active->value,
        'billing_cycle' => BillingCycle::Monthly->value,
        'currency_code' => 'EUR',
        'recurring_minor' => 10_00,
        'setup_minor' => 0,
        'module' => 'manual',
        'next_due_on' => $dueOn,
    ]);
}

it('records a charge and bills nothing by itself', function (): void {
    $item = aCharge();

    expect($item->total()->minorUnits)->toBe(7500)
        ->and($item->isCharged())->toBeFalse()
        // Recording is not invoicing. A second path that issued documents
        // would be a second place this product froze one.
        ->and(Invoice::query()->count())->toBe(0);
});

it('refuses a charge in a currency the customer is not billed in', function (): void {
    // There is no exchange rate anywhere in this product, so the charge would
    // wait for an invoice that never comes.
    expect(fn () => app(AddBillableItem::class)->handle(
        customer: $this->customer,
        description: 'In dollars',
        unitPrice: Money::ofMinor(5000, 'USD'),
        actor: $this->admin,
    ))->toThrow(BillableItemRefused::class);
});

it('rides along on the renewal invoice, and sums with it', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    renewingService('2026-10-06');
    aCharge(75_00);

    app(GenerateRenewalInvoices::class)->handle();

    $invoice = Invoice::query()->firstOrFail();

    // One invoice, so the customer pays once — which is the whole reason this
    // is folded into the renewal sweep rather than raised on its own.
    expect(Invoice::query()->count())->toBe(1)
        ->and($invoice->items)->toHaveCount(2)
        ->and($invoice->total_minor)->toBe(8500);
});

it('raises an invoice for a customer whose only due charge is a one-off', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    aCharge(75_00);

    app(GenerateRenewalInvoices::class)->handle();

    // Without this an hour of work recorded in March waits for a renewal that
    // may not be coming.
    $invoice = Invoice::query()->firstOrFail();

    expect($invoice->customer_id)->toBe($this->customer->id)
        ->and($invoice->total_minor)->toBe(7500)
        ->and($invoice->items)->toHaveCount(1);
});

/**
 * The rule that makes a retried run safe.
 */
it('cannot be charged twice', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $item = aCharge(75_00);

    app(GenerateRenewalInvoices::class)->handle();
    app(GenerateRenewalInvoices::class)->handle();

    expect(Invoice::query()->count())->toBe(1)
        ->and($item->fresh()->invoice_item_id)->not->toBeNull()
        ->and($item->fresh()->charged_at)->not->toBeNull();
});

it('waits until the date somebody asked it to wait for', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    aCharge(75_00, chargeOn: '2026-12-01');
    renewingService('2026-10-06');

    app(GenerateRenewalInvoices::class)->handle();

    // `charge_on` is "not before": the renewal still happens, and the charge
    // is not on it.
    $invoice = Invoice::query()->firstOrFail();

    expect($invoice->items)->toHaveCount(1)
        ->and($invoice->total_minor)->toBe(1000);
});

it('carries no period, so a payment does not advance a renewal date', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    aCharge(75_00);

    app(GenerateRenewalInvoices::class)->handle();

    // A one-off charge is not a term being bought, and `AdvanceRenewalDates`
    // reads the period to decide what a payment extends.
    expect(Invoice::query()->firstOrFail()->items->first()?->period_end)->toBeNull();
});

it('multiplies the quantity, and allows a negative amount', function (): void {
    $hours = aCharge(75_00, quantity: 3);

    expect($hours->total()->minorUnits)->toBe(22500);

    $reduction = app(AddBillableItem::class)->handle(
        customer: $this->customer,
        description: 'Agreed reduction',
        // A negotiated reduction is a one-off charge of a negative amount; a
        // credit note is the wrong document for something not yet invoiced.
        unitPrice: Money::ofMinor(-20_00, 'EUR'),
        actor: $this->admin,
    );

    expect($reduction->total()->minorUnits)->toBe(-2000);
});

it('refuses to take back a charge an invoice has already quoted', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 09:00:00');

    $item = aCharge(75_00);

    app(GenerateRenewalInvoices::class)->handle();

    // An invoice is frozen at issue, so deleting the row would leave a line
    // pointing at nothing.
    expect(fn () => app(AddBillableItem::class)->remove($item->fresh(), $this->admin))
        ->toThrow(BillableItemRefused::class);
});

it('drives the panel on the customer screen', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/customers/'.$this->customer->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Customers/Show')
            ->where('can.bill', true)
            ->has('billables'));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/customers/'.$this->customer->id.'/billables', [
            'description' => 'Migration, one hour',
            'quantity' => 2,
            'unit_amount_minor' => 7500,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $item = BillableItem::query()->firstOrFail();

    expect($item->total()->minorUnits)->toBe(15000);

    $this->actingAs($this->admin, 'staff')
        ->delete('/admin/billables/'.$item->id)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(BillableItem::query()->count())->toBe(0);
});

it('refuses somebody who may edit a customer but not bill one', function (): void {
    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($nobody->fresh(), 'staff')
        ->post('/admin/customers/'.$this->customer->id.'/billables', [
            'description' => 'Sneaky',
            'quantity' => 1,
            'unit_amount_minor' => 100,
        ])
        ->assertForbidden();
});
