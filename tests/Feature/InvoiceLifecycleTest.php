<?php

declare(strict_types=1);

use App\Application\Billing\AddCredit;
use App\Application\Billing\ApplyCredit;
use App\Application\Billing\CreateInvoiceFromOrder;
use App\Application\Billing\Exceptions\InvalidInvoiceTransition;
use App\Application\Billing\Exceptions\InvoiceNotIssuable;
use App\Application\Billing\Exceptions\OrderNotInvoiceable;
use App\Application\Billing\Exceptions\PaymentRefused;
use App\Application\Billing\IssueCreditNote;
use App\Application\Billing\IssueInvoice;
use App\Application\Billing\Ledger;
use App\Application\Billing\RecordPayment;
use App\Application\Billing\RecordPaymentRequest;
use App\Application\Billing\RefundPayment;
use App\Application\Billing\TransitionInvoice;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\TransactionKind;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Organizations\Models\Organization;

beforeEach(function (): void {
    $this->provider = Organization::factory()->provider()->create();
    $this->audit = $this->fakeAudit();

    $this->customer = Customer::factory()->forOrganization($this->provider)->create([
        'company_name' => 'Örnek Bilişim',
        'tax_id' => 'TR1234567890',
    ]);

    $this->order = Order::factory()->forCustomer($this->customer)->create([
        'status' => OrderStatus::AwaitingPayment->value,
        'currency_code' => 'EUR',
        'subtotal_minor' => 1998,
        'discount_minor' => 0,
        'setup_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 1998,
    ]);

    OrderItem::factory()->forOrder($this->order)->create([
        'name' => 'Starter Plan',
        'billing_cycle' => BillingCycle::Monthly->value,
        'quantity' => 2,
        'unit_recurring_minor' => 999,
        'line_recurring_minor' => 1998,
        'line_total_minor' => 1998,
    ]);
});

function invoiceFor(): Invoice
{
    return app(CreateInvoiceFromOrder::class)->handle(test()->order->fresh() ?? test()->order);
}

function issued(): Invoice
{
    return app(IssueInvoice::class)->handle(invoiceFor());
}

function pay(Invoice $invoice, int $minor, string $currency = 'EUR'): Payment
{
    return app(RecordPayment::class)->handle(
        $invoice->fresh() ?? $invoice,
        new RecordPaymentRequest(amount: Money::ofMinor($minor, $currency)),
    );
}

it('creates a draft invoice from an order', function (): void {
    $invoice = invoiceFor();

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->total->toDecimalString())->toBe('19.98')
        ->and($invoice->items()->count())->toBe(1)
        ->and($this->audit->actions())->toContain('billing.invoice.created');
});

it('copies the line wording onto the invoice rather than referencing it', function (): void {
    $invoice = invoiceFor();

    $line = $invoice->items()->sole();

    expect($line->description)->toBe('Starter Plan — Monthly')
        ->and($line->quantity)->toBe(2)
        ->and($line->line_amount->toDecimalString())->toBe('19.98');
});

it('gives a draft no sequence number', function (): void {
    // A number handed to a document that may never be issued leaves a gap
    // nobody can explain to an auditor.
    expect(invoiceFor()->number)->toStartWith('DRAFT-');
});

it('refuses to invoice an order twice', function (): void {
    invoiceFor();
    invoiceFor();
})->throws(OrderNotInvoiceable::class);

it('refuses to invoice a cancelled order', function (): void {
    $this->order->forceFill(['status' => OrderStatus::Cancelled->value])->save();

    invoiceFor();
})->throws(OrderNotInvoiceable::class);

it('numbers an invoice when it is issued', function (): void {
    $invoice = issued();

    expect($invoice->number)->toBe('INV-000001')
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid)
        ->and($invoice->issued_on)->not->toBeNull()
        ->and($this->audit->actions())->toContain('billing.invoice.issued');
});

it('numbers invoices in sequence', function (): void {
    $first = issued();

    $secondOrder = Order::factory()->forCustomer($this->customer)->create([
        'status' => OrderStatus::AwaitingPayment->value,
        'currency_code' => 'EUR',
        'total_minor' => 500,
    ]);
    OrderItem::factory()->forOrder($secondOrder)->create();

    $second = app(IssueInvoice::class)->handle(
        app(CreateInvoiceFromOrder::class)->handle($secondOrder),
    );

    expect($first->number)->toBe('INV-000001')
        ->and($second->number)->toBe('INV-000002');
});

it('freezes the bill-to party when the invoice is issued', function (): void {
    $invoice = issued();

    $this->customer->update(['company_name' => 'Renamed Ltd', 'tax_id' => 'TR9999999999']);

    $fresh = $invoice->fresh();

    // Correcting a customer record must not silently reissue every invoice
    // they were ever sent.
    expect($fresh?->bill_to_company)->toBe('Örnek Bilişim')
        ->and($fresh?->bill_to_tax_id)->toBe('TR1234567890');
});

it('refuses to issue an invoice with no lines', function (): void {
    $invoice = Invoice::factory()->forCustomer($this->customer)->draft()->create();

    app(IssueInvoice::class)->handle($invoice);
})->throws(InvoiceNotIssuable::class);

it('treats issuing twice as the document that already exists', function (): void {
    $invoice = issued();

    $again = app(IssueInvoice::class)->handle($invoice);

    expect($again->number)->toBe($invoice->number);
});

it('records a payment and settles the invoice', function (): void {
    $invoice = issued();

    pay($invoice, 1998);

    $fresh = $invoice->fresh();

    expect($fresh?->status)->toBe(InvoiceStatus::Paid)
        ->and($fresh?->paid->toDecimalString())->toBe('19.98')
        ->and($fresh?->balance()->isZero())->toBeTrue()
        ->and($fresh?->paid_at)->not->toBeNull()
        ->and($this->audit->actions())->toContain('billing.payment.recorded');
});

it('moves the order to paid when its invoice is paid', function (): void {
    $invoice = issued();

    pay($invoice, 1998);

    expect($this->order->fresh()?->status)->toBe(OrderStatus::Paid);
});

it('accepts a partial payment without complaint', function (): void {
    $invoice = issued();

    pay($invoice, 500);

    $fresh = $invoice->fresh();

    expect($fresh?->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($fresh?->balance()->toDecimalString())->toBe('14.98')
        ->and($this->order->fresh()?->status)->toBe(OrderStatus::AwaitingPayment);
});

it('sums partial payments to settle an invoice', function (): void {
    $invoice = issued();

    pay($invoice, 500);
    pay($invoice, 998);
    pay($invoice, 500);

    $fresh = $invoice->fresh();

    expect($fresh?->status)->toBe(InvoiceStatus::Paid)
        ->and($fresh?->paid->toDecimalString())->toBe('19.98');
});

it('turns an overpayment into account credit rather than an error', function (): void {
    $invoice = issued();

    pay($invoice, 2500);

    $fresh = $invoice->fresh();
    $credit = app(Ledger::class)->creditBalance($this->customer, 'EUR');

    expect($fresh?->status)->toBe(InvoiceStatus::Paid)
        // The invoice is paid exactly what it was for; the rest is credit.
        ->and($fresh?->paid->toDecimalString())->toBe('19.98')
        ->and($credit->toDecimalString())->toBe('5.02');
});

it('rebuilds the paid amount from the ledger', function (): void {
    $invoice = issued();

    pay($invoice, 500);
    pay($invoice, 700);

    // The rows are the truth; the column is a cache of them.
    expect(app(Ledger::class)->paidTowards($invoice->fresh() ?? $invoice)->minorUnits)
        ->toBe($invoice->fresh()?->paid->minorUnits);
});

it('refuses a payment in another currency', function (): void {
    pay(issued(), 1998, 'TRY');
})->throws(PaymentRefused::class);

it('refuses a payment on a draft', function (): void {
    pay(invoiceFor(), 500);
})->throws(PaymentRefused::class);

it('refuses a payment on an invoice already paid', function (): void {
    $invoice = issued();
    pay($invoice, 1998);

    pay($invoice, 100);
})->throws(PaymentRefused::class);

it('applies account credit to an invoice', function (): void {
    app(AddCredit::class)->handle($this->customer, Money::ofMinor(1000, 'EUR'), 'Goodwill');

    $invoice = issued();

    app(ApplyCredit::class)->handle($invoice, Money::ofMinor(1000, 'EUR'));

    $fresh = $invoice->fresh();

    expect($fresh?->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($fresh?->paid->toDecimalString())->toBe('10.00')
        ->and(app(Ledger::class)->creditBalance($this->customer, 'EUR')->isZero())->toBeTrue()
        ->and($this->audit->actions())->toContain('billing.credit.applied');
});

it('never applies more credit than the invoice owes', function (): void {
    app(AddCredit::class)->handle($this->customer, Money::ofMinor(5000, 'EUR'), 'Goodwill');

    $invoice = issued();

    app(ApplyCredit::class)->handle($invoice, Money::ofMinor(5000, 'EUR'));

    // Credit left over stays credit.
    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Paid)
        ->and(app(Ledger::class)->creditBalance($this->customer, 'EUR')->toDecimalString())->toBe('30.02');
});

it('refuses to apply credit the account does not hold', function (): void {
    app(ApplyCredit::class)->handle(issued(), Money::ofMinor(1000, 'EUR'));
})->throws(PaymentRefused::class);

it('records a refund and reopens the invoice', function (): void {
    $invoice = issued();
    $payment = pay($invoice, 1998);

    app(RefundPayment::class)->handle($payment, Money::ofMinor(998, 'EUR'), 'Partial service failure');

    $fresh = $invoice->fresh();

    expect($payment->fresh()?->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($fresh?->paid->toDecimalString())->toBe('10.00')
        ->and($this->audit->actions())->toContain('billing.payment.refunded');
});

it('refuses to refund more than a payment took', function (): void {
    $payment = pay(issued(), 1998);

    app(RefundPayment::class)->handle($payment, Money::ofMinor(5000, 'EUR'));
})->throws(PaymentRefused::class);

it('refuses to refund a failed payment', function (): void {
    $payment = Payment::factory()->status(PaymentStatus::Failed)->create(['amount_minor' => 1000]);

    app(RefundPayment::class)->handle($payment, Money::ofMinor(100, 'EUR'));
})->throws(PaymentRefused::class);

it('corrects an issued invoice with a credit note', function (): void {
    $invoice = issued();

    $note = app(IssueCreditNote::class)->handle($invoice, Money::ofMinor(998, 'EUR'), 'Billed in error');

    $fresh = $invoice->fresh();

    expect($note->number)->toBe('CN-000001')
        // The invoice itself is untouched; the ledger records the
        // correction.
        ->and($fresh?->total->toDecimalString())->toBe('19.98')
        ->and($fresh?->paid->toDecimalString())->toBe('9.98')
        ->and($this->audit->actions())->toContain('billing.credit_note.issued');
});

it('settles an invoice fully credited', function (): void {
    $invoice = issued();

    app(IssueCreditNote::class)->handle($invoice, Money::ofMinor(1998, 'EUR'), 'Cancelled after issue');

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Paid);
});

it('refuses a credit note larger than the invoice', function (): void {
    app(IssueCreditNote::class)->handle(issued(), Money::ofMinor(5000, 'EUR'), 'Too much');
})->throws(PaymentRefused::class);

it('refuses a credit note on a draft', function (): void {
    app(IssueCreditNote::class)->handle(invoiceFor(), Money::ofMinor(100, 'EUR'), 'Nope');
})->throws(PaymentRefused::class);

it('refuses an invoice transition the machine forbids', function (): void {
    $invoice = issued();
    app(TransitionInvoice::class)->handle($invoice, InvoiceStatus::Cancelled);

    app(TransitionInvoice::class)->handle($invoice, InvoiceStatus::Paid);
})->throws(InvalidInvoiceTransition::class);

it('keeps every ledger row positive and signed by its kind', function (): void {
    $invoice = issued();
    $payment = pay($invoice, 1998);

    app(RefundPayment::class)->handle($payment, Money::ofMinor(500, 'EUR'));

    $rows = Transaction::query()->withoutGlobalScope('organization')->get();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row->amount->isNegative())->toBeFalse();
    }

    expect($rows->firstWhere('kind', TransactionKind::Refund)?->amount->toDecimalString())->toBe('5.00');
});

it('keeps an invoice line readable after the invoice is issued and the order changes', function (): void {
    $invoice = issued();

    $this->order->allItems()->update(['name' => 'Something else']);

    expect(InvoiceItem::query()->withoutGlobalScope('organization')->sole()->description)
        ->toBe('Starter Plan — Monthly');
});
