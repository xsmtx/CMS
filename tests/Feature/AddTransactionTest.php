<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Billing\AddTransaction;
use App\Application\Billing\AddTransactionRequest;
use App\Application\Billing\Exceptions\PaymentRefused;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\TransactionKind;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use Carbon\CarbonImmutable;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Money that moved where the platform could not see it.
 *
 * The rule under test everywhere here: **this screen never writes an
 * invoice**. Naming an invoice routes the money through `RecordPayment`,
 * the one path that settles one, so an operator's bank transfer moves the
 * invoice, the order and the ledger exactly as a webhook would.
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

function transactionRequest(array $overrides = []): AddTransactionRequest
{
    return new AddTransactionRequest(
        amountIn: $overrides['amountIn'] ?? Money::zero('EUR'),
        amountOut: $overrides['amountOut'] ?? Money::zero('EUR'),
        occurredAt: $overrides['occurredAt'] ?? CarbonImmutable::now(),
        invoiceIds: $overrides['invoiceIds'] ?? [],
        toCreditBalance: $overrides['toCreditBalance'] ?? false,
        gateway: $overrides['gateway'] ?? 'manual',
        reference: $overrides['reference'] ?? null,
        description: $overrides['description'] ?? null,
        fees: $overrides['fees'] ?? null,
        recordedBy: $overrides['recordedBy'] ?? 'someone@example.test',
    );
}

it('settles the invoice it names, through the one path that settles one', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR',
        'subtotal_minor' => 5000,
        'total_minor' => 5000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    app(AddTransaction::class)->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(5000, 'EUR'),
        'invoiceIds' => [$invoice->number],
        'reference' => 'BANK-99',
    ]), $this->admin);

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->paid->minorUnits)->toBe(5000);

    $row = Transaction::query()->where('invoice_id', $invoice->id)->firstOrFail();

    expect($row->kind)->toBe(TransactionKind::Payment)
        ->and($row->reference)->toBe('BANK-99')
        ->and($row->gateway)->toBe('manual');
});

it('splits one transfer across several invoices, in the order given', function (): void {
    $first = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR', 'subtotal_minor' => 3000, 'total_minor' => 3000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);
    $second = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR', 'subtotal_minor' => 4000, 'total_minor' => 4000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    app(AddTransaction::class)->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(5000, 'EUR'),
        'invoiceIds' => [$first->number, $second->number],
    ]), $this->admin);

    expect($first->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($second->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($second->fresh()->paid->minorUnits)->toBe(2000);
});

it('banks what is left over as credit', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR', 'subtotal_minor' => 1000, 'total_minor' => 1000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    app(AddTransaction::class)->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(2500, 'EUR'),
        'invoiceIds' => [$invoice->number],
        'toCreditBalance' => true,
    ]), $this->admin);

    $credit = Transaction::query()
        ->where('customer_id', $this->customer->id)
        ->where('kind', TransactionKind::CreditAdded->value)
        ->firstOrFail();

    expect($credit->amount->minorUnits)->toBe(1500)
        ->and($credit->credit_balance->minorUnits)->toBe(1500);
});

it('refuses money that is attributed to nothing', function (): void {
    app(AddTransaction::class)->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(2500, 'EUR'),
    ]), $this->admin);
})->throws(PaymentRefused::class);

it('refuses a row that moves money both ways, and one that moves none', function (): void {
    $both = fn (): array => app(AddTransaction::class)->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(100, 'EUR'),
        'amountOut' => Money::ofMinor(100, 'EUR'),
        'toCreditBalance' => true,
    ]), $this->admin);

    $neither = fn (): array => app(AddTransaction::class)->handle(
        $this->customer,
        transactionRequest(['toCreditBalance' => true]),
        $this->admin,
    );

    expect($both)->toThrow(PaymentRefused::class)
        ->and($neither)->toThrow(PaymentRefused::class);
});

it('records the fee without shrinking the payment', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR', 'subtotal_minor' => 10000, 'total_minor' => 10000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    app(AddTransaction::class)->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(10000, 'EUR'),
        'invoiceIds' => [$invoice->number],
        'fees' => Money::ofMinor(290, 'EUR'),
    ]), $this->admin);

    $row = Transaction::query()->where('invoice_id', $invoice->id)->firstOrFail();

    // The customer paid all of it and the invoice is settled in full. A fee
    // subtracted from the amount would make one of those two numbers a lie.
    expect($row->amount->minorUnits)->toBe(10000)
        ->and($row->fees->minorUnits)->toBe(290)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('takes money back out and rebuilds the invoice from the rows', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR', 'subtotal_minor' => 4000, 'total_minor' => 4000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    $add = app(AddTransaction::class);

    $add->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(4000, 'EUR'),
        'invoiceIds' => [$invoice->number],
    ]), $this->admin);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    $add->handle($this->customer, transactionRequest([
        'amountOut' => Money::ofMinor(1500, 'EUR'),
        'invoiceIds' => [$invoice->number],
    ]), $this->admin);

    $invoice->refresh();

    // An issued invoice is frozen (ADR 0023), so it does not walk
    // backwards to partially paid. `Refunded` is the one move out of
    // `Paid`, and the ledger is what says how much came back.
    expect($invoice->paid->minorUnits)->toBe(2500)
        ->and($invoice->status)->toBe(InvoiceStatus::Refunded);
});

it('will not take an invoice belonging to somebody else', function (): void {
    $other = Customer::factory()->create(['currency_code' => 'EUR']);
    $invoice = Invoice::factory()->forCustomer($other)->create([
        'currency_code' => 'EUR', 'subtotal_minor' => 1000, 'total_minor' => 1000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    app(AddTransaction::class)->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(1000, 'EUR'),
        'invoiceIds' => [$invoice->number],
        'toCreditBalance' => true,
    ]), $this->admin);

    // The number was ignored, not obeyed: the money became credit on the
    // client the operator actually chose.
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->where('customer_id', $this->customer->id)->count())->toBe(1);
});

it('refuses an amount in a currency the invoice is not in', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR', 'subtotal_minor' => 1000, 'total_minor' => 1000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    app(AddTransaction::class)->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(1000, 'TRY'),
        'amountOut' => Money::zero('TRY'),
        'invoiceIds' => [$invoice->number],
    ]), $this->admin);
})->throws(PaymentRefused::class);

it('opens the form and writes a row from it', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR', 'subtotal_minor' => 2000, 'total_minor' => 2000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/transactions/add?customer='.$this->customer->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Billing/AddTransaction')
            ->where('chosen.id', $this->customer->id)
            ->has('openInvoices', 1)
            ->has('currencies'));

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/transactions', [
            'customer_id' => $this->customer->id,
            'occurred_at' => now()->toDateString(),
            'currency_code' => 'EUR',
            'amount_in' => '20.00',
            'fees' => '0.50',
            'invoice_ids' => $invoice->number,
            'description' => 'Wire from the bank',
            'reference' => 'TRF-1',
            'gateway' => 'manual',
        ])
        ->assertRedirect('/admin/transactions');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('finds a client by the whole name and shows nobody without a search', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/transactions/add')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('candidates', 0)
            ->where('chosen', null));
});

it('charts money in and out from the same criteria as the list', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->create([
        'currency_code' => 'EUR', 'subtotal_minor' => 3000, 'total_minor' => 3000,
        'status' => InvoiceStatus::Unpaid->value,
    ]);

    $add = app(AddTransaction::class);

    $add->handle($this->customer, transactionRequest([
        'amountIn' => Money::ofMinor(3000, 'EUR'),
        'invoiceIds' => [$invoice->number],
    ]), $this->admin);

    $add->handle($this->customer, transactionRequest([
        'amountOut' => Money::ofMinor(1000, 'EUR'),
        'invoiceIds' => [$invoice->number],
    ]), $this->admin);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/transactions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Billing/Transactions')
            ->has('flow.in')
            ->has('flow.out')
            ->where('flow.currency', 'EUR'));

    // Narrowing the list narrows the chart: one direction, one total.
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/transactions?direction=out')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.increasesBalance', false));
});

it('refuses to write a transaction without the permission to record money', function (): void {
    $support = StaffUser::factory()->create();
    $support->assignRole(SystemRole::Support);

    $this->actingAs($support->fresh(), 'staff')
        ->get('/admin/transactions/add')
        ->assertForbidden();
});
