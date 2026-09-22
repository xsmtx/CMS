<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Access\Models\Permission;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Billing\Models\CreditNote;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Organizations\Models\Organization;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->customer = Customer::factory()->forOrganization($this->admin->organization_id)->create();
});

function billingStaffWith(array $slugs): StaffUser
{
    $role = Role::query()->create([
        'name' => 'Billing limited',
        'slug' => 'billing-limited-'.uniqid(),
        'scope' => RoleScope::Staff->value,
        'is_system' => false,
    ]);

    $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));

    $staff = StaffUser::factory()->create(['organization_id' => test()->admin->organization_id]);
    $staff->roles()->attach($role);

    return $staff->fresh() ?? $staff;
}

function issuedInvoice(int $minor = 1998): Invoice
{
    $invoice = Invoice::factory()
        ->forCustomer(test()->customer)
        ->status(InvoiceStatus::Unpaid)
        ->totalling($minor)
        ->create();

    InvoiceItem::factory()->forInvoice($invoice)->create();

    return $invoice;
}

it('refuses the invoice list without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin/invoices')
        ->assertForbidden();
});

it('lists invoices with what is outstanding, grouped by currency', function (): void {
    issuedInvoice(1000);
    Invoice::factory()->forCustomer($this->customer)->status(InvoiceStatus::Unpaid)
        ->totalling(5000, 'TRY')->create();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/invoices')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Invoices/Index')
            ->has('invoices.data', 2)
            // Adding euros to lira is the mistake this platform refuses
            // everywhere else.
            ->has('owed', 2));
});

it('shows an invoice with its lines and ledger', function (): void {
    $invoice = issuedInvoice();

    $this->actingAs($this->admin, 'staff')
        ->get("/admin/invoices/{$invoice->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Invoices/Show')
            ->has('invoice.items', 1)
            ->where('invoice.number', $invoice->number));
});

it('raises an invoice for an order', function (): void {
    $order = Order::factory()->forCustomer($this->customer)->create([
        'status' => OrderStatus::AwaitingPayment->value,
        'total_minor' => 1998,
    ]);
    OrderItem::factory()->forOrder($order)->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/orders/{$order->id}/invoice")
        ->assertRedirectContains('/admin/invoices/');

    expect(Invoice::query()->where('order_id', $order->id)->exists())->toBeTrue();
});

it('issues a draft', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->draft()->create();
    InvoiceItem::factory()->forInvoice($invoice)->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/issue")
        ->assertSessionHasNoErrors();

    $fresh = $invoice->fresh();

    expect($fresh?->status)->toBe(InvoiceStatus::Unpaid)
        ->and($fresh?->number)->toStartWith('INV-');
});

it('refuses to issue a draft with no lines', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->draft()->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/issue")
        ->assertStatus(412);
});

it('records a payment against an invoice', function (): void {
    $invoice = issuedInvoice();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/payments", [
            'amount_minor' => 1998,
            'gateway' => 'manual',
            'reference' => 'TRF-9912',
        ])
        ->assertSessionHasNoErrors();

    $fresh = $invoice->fresh();

    expect($fresh?->status)->toBe(InvoiceStatus::Paid)
        ->and($fresh?->paid->minorUnits)->toBe(1998);
});

it('refuses a payment to a staff member who may only view invoices', function (): void {
    $staff = billingStaffWith(['billing.invoices.view', 'billing.invoices.manage']);
    $invoice = issuedInvoice();

    $this->actingAs($staff, 'staff')
        ->post("/admin/invoices/{$invoice->id}/payments", ['amount_minor' => 100, 'gateway' => 'manual'])
        ->assertForbidden();

    $this->actingAs($staff, 'staff')
        ->get("/admin/invoices/{$invoice->id}")
        ->assertOk();
});

it('refunds a payment with a reason', function (): void {
    $invoice = issuedInvoice();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/payments", ['amount_minor' => 1998, 'gateway' => 'manual']);

    $payment = Payment::query()->withoutGlobalScope('organization')->sole();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/payments/{$payment->id}/refund", [
            'amount_minor' => 998,
            'reason' => 'Service was down for a week',
        ])
        ->assertSessionHasNoErrors();

    expect($invoice->fresh()?->paid->toDecimalString())->toBe('10.00');
});

it('demands a reason for a refund', function (): void {
    $invoice = issuedInvoice();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/payments", ['amount_minor' => 1998, 'gateway' => 'manual']);

    $payment = Payment::query()->withoutGlobalScope('organization')->sole();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/payments/{$payment->id}/refund", ['amount_minor' => 100])
        ->assertSessionHasErrors('reason');
});

it('refuses to refund a payment from another invoice', function (): void {
    $invoice = issuedInvoice();
    $other = issuedInvoice();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$other->id}/payments", ['amount_minor' => 1998, 'gateway' => 'manual']);

    $payment = Payment::query()->withoutGlobalScope('organization')->sole();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/payments/{$payment->id}/refund", [
            'amount_minor' => 100,
            'reason' => 'Wrong invoice',
        ])
        ->assertNotFound();
});

it('adds and applies account credit', function (): void {
    $invoice = issuedInvoice();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/credit/add", [
            'amount_minor' => 1000,
            'reason' => 'Goodwill after an outage',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/credit/apply", [
            'amount_minor' => 1000,
            'reason' => 'Applied to invoice',
        ])
        ->assertSessionHasNoErrors();

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoice->fresh()?->paid->toDecimalString())->toBe('10.00');
});

it('issues a credit note against an invoice', function (): void {
    $invoice = issuedInvoice();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/credit-note", [
            'amount_minor' => 998,
            'reason' => 'Billed for a month the customer did not have',
        ])
        ->assertSessionHasNoErrors();

    $note = CreditNote::query()->withoutGlobalScope('organization')->sole();

    expect($note->number)->toStartWith('CN-')
        // The invoice itself is untouched.
        ->and($invoice->fresh()?->total->toDecimalString())->toBe('19.98')
        ->and($invoice->fresh()?->paid->toDecimalString())->toBe('9.98');
});

it('refuses a credit note to a staff member without the credit permission', function (): void {
    $staff = billingStaffWith(['billing.invoices.view']);
    $invoice = issuedInvoice();

    $this->actingAs($staff, 'staff')
        ->post("/admin/invoices/{$invoice->id}/credit-note", ['amount_minor' => 100, 'reason' => 'Nope'])
        ->assertForbidden();
});

it('cancels a draft', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->draft()->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/invoices/{$invoice->id}/cancel", ['reason' => 'Raised by mistake'])
        ->assertSessionHasNoErrors();

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Cancelled);
});

it('does not reach an invoice belonging to another organization', function (): void {
    $reseller = Organization::factory()->reseller(
        Organization::query()->whereNull('parent_id')->sole()
    )->create();

    $theirs = Invoice::factory()->forCustomer(
        Customer::factory()->forOrganization($reseller)->create()
    )->create();

    $mine = issuedInvoice();

    $staff = StaffUser::factory()->create(['organization_id' => $reseller->id]);

    $this->actingAs($staff, 'staff')
        ->get("/admin/invoices/{$mine->id}")
        ->assertNotFound();

    $this->actingAs($staff, 'staff')
        ->get("/admin/invoices/{$theirs->id}")
        ->assertForbidden();
});
