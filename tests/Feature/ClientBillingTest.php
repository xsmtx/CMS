<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\TransactionKind;
use App\Domain\Crm\AddressType;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->customer = Customer::factory()->create(['company_name' => 'Northwind Supply']);

    $this->owner = Contact::factory()->forCustomer($this->customer)->primary()->create();
    $this->owner->assignRole(SystemRole::AccountOwner);
    $this->owner = $this->owner->fresh();

    $this->member = Contact::factory()->forCustomer($this->customer)->create();
    $this->member->assignRole(SystemRole::PortalMember);
    $this->member = $this->member->fresh();

    $this->invoice = Invoice::factory()
        ->forCustomer($this->customer)
        ->status(InvoiceStatus::Unpaid)
        ->totalling(14990)
        ->create();

    InvoiceItem::factory()->forInvoice($this->invoice)->create();
});

it('lists the invoices belonging to this customer', function (): void {
    $this->actingAs($this->owner, 'client')
        ->get('/client/billing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Billing/Invoices')
            ->has('invoices.data', 1)
            ->where('invoices.data.0.number', $this->invoice->number)
            // Grouped by currency, never summed across them.
            ->has('outstanding', 1));
});

it('never lists a draft', function (): void {
    Invoice::factory()->forCustomer($this->customer)->draft()->create();

    $this->actingAs($this->owner, 'client')
        ->get('/client/billing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('invoices.data', 1));
});

it('shows an invoice with its lines and how to pay it', function (): void {
    $this->actingAs($this->owner, 'client')
        ->get("/client/billing/invoices/{$this->invoice->number}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Billing/Invoice')
            ->where('invoice.number', $this->invoice->number)
            ->has('invoice.items', 1)
            ->where('can.pay', true));
});

it('does not hand the customer an operator note', function (): void {
    $this->invoice->forceFill(['notes' => 'Chased twice, poor payer.'])->save();

    $this->actingAs($this->owner, 'client')
        ->get("/client/billing/invoices/{$this->invoice->number}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->missing('invoice.notes'))
        ->assertDontSee('poor payer');
});

it('hides another customers invoice behind a 404, never a 403', function (): void {
    $stranger = Invoice::factory()
        ->forCustomer(Customer::factory()->create())
        ->status(InvoiceStatus::Unpaid)
        ->create();

    // A 403 would confirm the invoice exists, and invoice numbers are
    // sequential.
    $this->actingAs($this->owner, 'client')
        ->get("/client/billing/invoices/{$stranger->number}")
        ->assertNotFound();
});

it('refuses billing to a portal member', function (): void {
    $this->actingAs($this->member, 'client')
        ->get('/client/billing')
        ->assertForbidden();
});

it('shows the ledger that explains the credit balance', function (): void {
    Transaction::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'kind' => TransactionKind::CreditAdded->value,
        'amount_minor' => 5000,
        'credit_balance_minor' => 5000,
        'currency_code' => $this->customer->currency_code,
    ]);

    $this->actingAs($this->owner, 'client')
        ->get('/client/billing/transactions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Billing/Transactions')
            ->has('transactions.data', 1)
            ->where('transactions.data.0.kind', 'credit_added')
            ->where('credit.balance', '€50.00'));
});

it('shows the billing details the next invoice will use', function (): void {
    $this->actingAs($this->owner, 'client')
        ->get('/client/billing/details')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Client/Billing/Details')
            ->where('details.companyName', 'Northwind Supply'));
});

it('saves a billing address and leaves issued invoices alone', function (): void {
    $issued = $this->invoice->only(['bill_to_name', 'bill_to_address']);

    $this->actingAs($this->owner, 'client')
        ->put('/client/billing/details', [
            'company_name' => 'Northwind Supply BV',
            'tax_id' => 'NL123456789B01',
            'line_one' => 'Keizersgracht 1',
            'city' => 'Amsterdam',
            'postal_code' => '1015 CJ',
            'country_code' => 'nl',
        ])
        ->assertRedirect();

    $address = $this->customer->fresh()?->addressFor(AddressType::Billing);

    expect($address?->line_one)->toBe('Keizersgracht 1')
        // Stored upper case, because that is what an ISO code is.
        ->and($address?->country_code)->toBe('NL')
        ->and($this->invoice->fresh()?->only(['bill_to_name', 'bill_to_address']))->toBe($issued);
});

it('refuses a half-filled billing address', function (): void {
    $this->actingAs($this->owner, 'client')
        ->put('/client/billing/details', ['line_one' => 'Keizersgracht 1'])
        ->assertSessionHasErrors(['city', 'country_code']);
});

it('lists a stored payment method without its token', function (): void {
    $method = PaymentMethod::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
        'token' => 'pm_secret_value',
        'last_four' => '4242',
    ]);

    $this->actingAs($this->owner, 'client')
        ->get('/client/billing/details')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('methods', 1)
            ->where('methods.0.lastFour', '4242')
            ->missing('methods.0.token'))
        ->assertDontSee('pm_secret_value');

    expect($method->fresh()?->token)->toBe('pm_secret_value');
});

it('removes a stored payment method', function (): void {
    $method = PaymentMethod::factory()->create([
        'organization_id' => $this->customer->organization_id,
        'customer_id' => $this->customer->id,
    ]);

    $this->actingAs($this->owner, 'client')
        ->delete("/client/billing/methods/{$method->id}")
        ->assertRedirect();

    expect(PaymentMethod::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('will not remove somebody elses stored payment method', function (): void {
    $other = Customer::factory()->create();
    $method = PaymentMethod::factory()->create([
        'organization_id' => $other->organization_id,
        'customer_id' => $other->id,
    ]);

    $this->actingAs($this->owner, 'client')
        ->delete("/client/billing/methods/{$method->id}")
        ->assertNotFound();

    expect(PaymentMethod::query()->withoutGlobalScope('organization')->count())->toBe(1);
});
