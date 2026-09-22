<?php

declare(strict_types=1);

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Shared\Money;
use App\Infrastructure\Billing\Models\CreditNote;
use App\Infrastructure\Billing\Models\GatewayEventRecord;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function (): void {
    $this->provider = Organization::factory()->provider()->create();
    $this->reseller = Organization::factory()->reseller($this->provider)->create();
    $this->context = app(OrganizationContext::class);
});

it('round-trips every invoice amount as money', function (): void {
    $invoice = Invoice::factory()->create([
        'currency_code' => 'TRY',
        'subtotal_minor' => 14990,
        'tax_minor' => 2998,
        'total_minor' => 17988,
        'paid_minor' => 5000,
    ]);

    $fresh = Invoice::query()->withoutGlobalScope('organization')->findOrFail($invoice->id);

    expect($fresh->total->toDecimalString())->toBe('179.88')
        ->and($fresh->total->currency->code)->toBe('TRY')
        ->and($fresh->balance()->toDecimalString())->toBe('129.88')
        ->and($fresh->isFullyPaid())->toBeFalse();
});

it('derives the balance rather than storing it', function (): void {
    $invoice = Invoice::factory()->create(['total_minor' => 1000, 'paid_minor' => 1000]);

    expect($invoice->balance()->isZero())->toBeTrue()
        ->and($invoice->isFullyPaid())->toBeTrue();
});

it('treats an overpaid invoice as fully paid rather than negative', function (): void {
    $invoice = Invoice::factory()->create(['total_minor' => 1000, 'paid_minor' => 1500]);

    expect($invoice->isFullyPaid())->toBeTrue()
        ->and($invoice->balance()->isNegative())->toBeTrue();
});

it('knows when an invoice is past due without waiting for a scheduler', function (): void {
    // The status only changes when something runs, and nothing runs at
    // midnight until Phase 9.
    $overdue = Invoice::factory()->create([
        'status' => InvoiceStatus::Unpaid->value,
        'due_on' => now()->subDay()->toDateString(),
    ]);

    $paid = Invoice::factory()->create([
        'status' => InvoiceStatus::Paid->value,
        'due_on' => now()->subDay()->toDateString(),
    ]);

    expect($overdue->isPastDue())->toBeTrue()
        ->and($paid->isPastDue())->toBeFalse();
});

it('keeps an invoice line readable after the order item is deleted', function (): void {
    $order = Order::factory()->create();
    $orderItem = OrderItem::factory()->forOrder($order)->create();

    $item = InvoiceItem::factory()->create([
        'order_item_id' => $orderItem->id,
        'description' => 'Starter Plan — Monthly',
    ]);

    $orderItem->delete();

    $fresh = InvoiceItem::query()->withoutGlobalScope('organization')->findOrFail($item->id);

    expect($fresh->description)->toBe('Starter Plan — Monthly')
        ->and($fresh->order_item_id)->toBeNull();
});

it('reports what a payment could still refund', function (): void {
    $payment = Payment::factory()->create([
        'amount_minor' => 10000,
        'refunded_minor' => 2500,
        'status' => PaymentStatus::PartiallyRefunded->value,
    ]);

    expect($payment->refundable()->toDecimalString())->toBe('75.00');
});

it('reports nothing refundable on a failed payment', function (): void {
    $payment = Payment::factory()->status(PaymentStatus::Failed)->create(['amount_minor' => 10000]);

    expect($payment->refundable()->isZero())->toBeTrue();
});

it('refuses to rewrite the ledger', function (): void {
    Transaction::factory()->create()->update(['amount_minor' => 1]);
})->throws(RuntimeException::class);

it('refuses to delete a ledger row', function (): void {
    Transaction::factory()->create()->delete();
})->throws(RuntimeException::class);

it('refuses to change an issued credit note', function (): void {
    CreditNote::factory()->create()->update(['amount_minor' => 1]);
})->throws(RuntimeException::class);

it('refuses to delete a credit note', function (): void {
    CreditNote::factory()->create()->delete();
})->throws(RuntimeException::class);

it('refuses two records of the same gateway event', function (): void {
    GatewayEventRecord::factory()->create(['gateway' => 'stripe', 'event_id' => 'evt_1']);

    GatewayEventRecord::factory()->create(['gateway' => 'stripe', 'event_id' => 'evt_1']);
})->throws(UniqueConstraintViolationException::class);

it('allows the same event id from two different gateways', function (): void {
    GatewayEventRecord::factory()->create(['gateway' => 'stripe', 'event_id' => 'evt_1']);
    GatewayEventRecord::factory()->create(['gateway' => 'paypal', 'event_id' => 'evt_1']);

    expect(GatewayEventRecord::query()->withoutGlobalScope('organization')->count())->toBe(2);
});

it('never exposes a stored payment method token', function (): void {
    $method = PaymentMethod::factory()->create(['brand' => 'visa', 'last_four' => '4242']);

    // The token is a credential at the gateway. An accidental toArray()
    // must not put it in a response or a log line.
    expect($method->toArray())->not->toHaveKey('token')
        ->and(json_encode($method))->not->toContain($method->getAttribute('token'))
        ->and($method->displayName())->toBe('visa ····4242');
});

it('allows only one default payment method per customer', function (): void {
    $customer = Customer::factory()->forOrganization($this->provider)->create();

    $first = PaymentMethod::factory()->create(['customer_id' => $customer->id, 'is_default' => true]);
    $second = PaymentMethod::factory()->create(['customer_id' => $customer->id, 'is_default' => true]);

    expect($first->fresh()?->is_default)->toBeFalse()
        ->and($second->fresh()?->is_default)->toBeTrue();
});

it('scopes invoices, payments and the ledger to their organization', function (): void {
    Invoice::factory()->forCustomer(Customer::factory()->forOrganization($this->provider)->create())->create();
    Invoice::factory()->forCustomer(Customer::factory()->forOrganization($this->reseller)->create())->create();

    $this->context->runAs($this->reseller->id, function (): void {
        expect(Invoice::query()->count())->toBe(1)
            ->and(Payment::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);
    });
});

it('keeps a money amount and its currency together on the ledger', function (): void {
    $transaction = Transaction::factory()->create(['currency_code' => 'TRY', 'amount_minor' => 12345]);

    $fresh = Transaction::query()->withoutGlobalScope('organization')->findOrFail($transaction->id);

    expect($fresh->amount)->toBeInstanceOf(Money::class)
        ->and($fresh->amount->toDecimalString())->toBe('123.45')
        ->and($fresh->amount->currency->code)->toBe('TRY');
});
