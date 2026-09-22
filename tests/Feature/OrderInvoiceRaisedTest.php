<?php

declare(strict_types=1);

use App\Application\Billing\RaiseInvoiceForOrder;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Organizations\Models\Organization;
use Database\Seeders\ProviderOrganizationSeeder;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    $this->provider = Organization::query()->withoutGlobalScope('organization')->whereNull('parent_id')->sole();
    $this->customer = Customer::factory()->forOrganization($this->provider)->create();
});

function orderAwaiting(Customer $customer, OrderStatus $status = OrderStatus::AwaitingPayment, int $total = 14990): Order
{
    $order = Order::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'status' => $status->value,
        'total_minor' => $total,
        'subtotal_minor' => $total,
    ]);

    OrderItem::factory()->create([
        'organization_id' => $order->organization_id,
        'order_id' => $order->id,
    ]);

    return $order;
}

it('raises and issues an invoice for an order awaiting payment', function (): void {
    $invoice = app(RaiseInvoiceForOrder::class)->handle(orderAwaiting($this->customer));

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice?->status)->toBe(InvoiceStatus::Unpaid)
        // Issued, so it carries a real number rather than a draft placeholder.
        ->and($invoice?->number)->toStartWith('INV-')
        ->and($invoice?->total->minorUnits)->toBe(14990);
});

it('raises nothing for an order held for review', function (): void {
    $order = orderAwaiting($this->customer, OrderStatus::FraudReview);

    // Asking somebody to pay for something we have not decided to sell them
    // is the wrong order of events.
    expect(app(RaiseInvoiceForOrder::class)->handle($order))->toBeNull()
        ->and(Invoice::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('raises nothing for an order that costs nothing', function (): void {
    $order = orderAwaiting($this->customer, total: 0);

    expect(app(RaiseInvoiceForOrder::class)->handle($order))->toBeNull()
        ->and(Invoice::query()->withoutGlobalScope('organization')->count())->toBe(0);
});

it('returns the same invoice when asked twice', function (): void {
    $order = orderAwaiting($this->customer);
    $raiser = app(RaiseInvoiceForOrder::class);

    $first = $raiser->handle($order);
    $second = $raiser->handle($order->fresh() ?? $order);

    expect($second?->id)->toBe($first?->id)
        ->and(Invoice::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

it('raises a fresh invoice after the first was cancelled', function (): void {
    $order = orderAwaiting($this->customer);
    $raiser = app(RaiseInvoiceForOrder::class);

    $first = $raiser->handle($order);
    $first?->forceFill(['status' => InvoiceStatus::Cancelled->value])->save();

    $second = $raiser->handle($order->fresh() ?? $order);

    expect($second?->id)->not->toBe($first?->id)
        ->and($second?->status)->toBe(InvoiceStatus::Unpaid);
});
