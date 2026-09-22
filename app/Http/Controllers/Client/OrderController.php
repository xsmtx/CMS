<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Billing\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Ordering\Models\OrderItemOption;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The orders the customer has placed.
 *
 * Phase 3 built the order and showed it once, on the confirmation page, to
 * a browser that then closed. This is where it lives afterwards.
 *
 * The lines are the copy the order froze when it was placed
 * ([ADR 0021](../../docs/adr/0021-order-lines-copy-the-catalog.md)), which
 * is the point: a customer reading last March's order sees what they
 * actually bought, not what that product is called today.
 *
 * The risk decision never appears. Telling a customer which rule held their
 * order is telling whoever is testing the rules.
 */
final class OrderController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
    ) {}

    public function index(): Response
    {
        $this->authorizeOrders();

        $orders = $this->customer
            ->owned(Order::query())
            ->whereNot('status', 'draft')
            ->latest('placed_at')
            ->latest()
            ->paginate(20);

        return Inertia::render('Client/Orders/Index', [
            'orders' => [
                'data' => array_map($this->row(...), $orders->items()),
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(string $number): Response
    {
        $this->authorizeOrders();

        $order = $this->customer->find(
            Order::query()->where('number', $number)->whereNot('status', 'draft'),
        );

        $order->load(['items.options', 'items.children.options']);

        $invoice = Invoice::query()
            ->where('order_id', $order->id)
            ->whereNot('status', InvoiceStatus::Draft->value)
            ->whereNot('status', InvoiceStatus::Cancelled->value)
            ->first();

        return Inertia::render('Client/Orders/Show', [
            'order' => [
                ...$this->row($order),
                'subtotal' => $order->subtotal->format(app()->getLocale()),
                'discount' => $order->discount->isZero()
                    ? null
                    : $order->discount->format(app()->getLocale()),
                'setup' => $order->setup->isZero()
                    ? null
                    : $order->setup->format(app()->getLocale()),
                'tax' => $order->tax->format(app()->getLocale()),
                'recurringTotal' => $order->recurring_total->isZero()
                    ? null
                    : $order->recurring_total->format(app()->getLocale()),
                'promotionCode' => $order->promotion_code,
                'items' => $order->items
                    ->map(fn (OrderItem $item): array => $this->item($item))
                    ->values()
                    ->all(),
            ],
            'invoice' => $invoice === null ? null : [
                'number' => $invoice->number,
                'status' => (string) __($invoice->status->labelKey()),
                'balance' => $invoice->balance()->format(app()->getLocale()),
                'isOwed' => $invoice->status->isOwed(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'statusLabel' => (string) __($order->status->labelKey()),
            'total' => $order->total->format(app()->getLocale()),
            'placedAt' => $order->placed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(OrderItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'cycleLabel' => $item->billing_cycle === null
                ? null
                : (string) __('catalog.cycles.'.$item->billing_cycle->value),
            'domain' => $item->domain,
            'quantity' => $item->quantity,
            'lineTotal' => $item->line_recurring->plus($item->line_setup)->format(app()->getLocale()),
            'options' => $item->options
                ->map(fn (OrderItemOption $option): array => [
                    'group' => $option->group_name,
                    'label' => $option->label,
                ])
                ->values()
                ->all(),
            'children' => $item->children
                ->map(fn (OrderItem $child): array => $this->item($child))
                ->values()
                ->all(),
        ];
    }

    private function authorizeOrders(): void
    {
        if (! $this->actor->can('portal.orders.view')) {
            throw new ForbiddenException(__('ordering.not_permitted'));
        }
    }
}
