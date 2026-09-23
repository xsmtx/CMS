<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Ordering\SearchOrders;
use App\Application\Ordering\TransitionOrder;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Ordering\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ordering\OrderStatusRequest;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Ordering\Models\OrderItemOption;
use App\Infrastructure\Ordering\Models\OrderStatusChange;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OrderController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Request $request, SearchOrders $search): Response
    {
        $this->authorize('viewAny', Order::class);

        /** @var array<string, string> $criteria */
        $criteria = array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $request->only(['status', 'number', 'client', 'payment', 'from', 'to', 'amount', 'ip']),
        );

        $orders = $search->paginate($criteria);

        return Inertia::render('Admin/Orders/Index', [
            'orders' => [
                'data' => array_map(
                    $this->row(...),
                    $orders->items(),
                ),
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'filters' => $criteria,
            'statuses' => self::statuses(),
            'gateways' => $search->gateways(),
            'reviewCount' => Order::query()->awaitingReview()->count(),
        ]);
    }

    /**
     * The queue of orders a human has to decide about.
     *
     * Its own screen rather than a filter on the list, because it is a
     * to-do list: an order sitting here is not moving until someone acts.
     */
    public function review(): Response
    {
        $this->authorize('viewAny', Order::class);

        return Inertia::render('Admin/Orders/Review', [
            'orders' => Order::query()
                ->awaitingReview()
                ->with(Customer::displayNameWith('customer'))
                ->orderBy('placed_at')
                ->get()
                ->map(fn (Order $order): array => [
                    ...$this->row($order),
                    'riskReasons' => $this->riskReasons($order),
                ])
                ->values()
                ->all(),
            'canReview' => $this->actor->can('reviewAny', Order::class),
        ]);
    }

    public function show(Order $order): Response
    {
        $this->authorize('view', $order);

        $order->load([
            ...Customer::displayNameWith('customer'),
            'contact',
            'items.options',
            'items.children.options',
            'statusHistory',
        ]);

        $invoice = Invoice::query()
            ->where('order_id', $order->id)
            ->whereNot('status', InvoiceStatus::Cancelled->value)
            ->first();

        return Inertia::render('Admin/Orders/Show', [
            'order' => [
                ...$this->row($order),
                'contact' => $order->contact?->displayName(),
                'termsAcceptedAt' => $order->terms_accepted_at?->toIso8601String(),
                'termsVersion' => $order->terms_version,
                'ipAddress' => $order->ip_address,
                'notes' => $order->notes,
                'promotionCode' => $order->promotion_code,
                'subtotal' => $order->subtotal->format(app()->getLocale()),
                'discount' => $order->discount->format(app()->getLocale()),
                'setup' => $order->setup->format(app()->getLocale()),
                'tax' => $order->tax->format(app()->getLocale()),
                'recurringTotal' => $order->recurring_total->format(app()->getLocale()),
                'taxBreakdown' => $order->tax_breakdown ?? [],
                'riskReasons' => $this->riskReasons($order),
                'riskScore' => $order->risk_score,
                'riskReviewedAt' => $order->risk_reviewed_at?->toIso8601String(),
                'riskReviewedBy' => $order->risk_reviewed_by,
                'items' => $order->items->map(fn (OrderItem $item): array => $this->item($item))->values()->all(),
                'history' => $order->statusHistory
                    ->map(fn (OrderStatusChange $change): array => [
                        'from' => $change->from_status?->value,
                        'to' => $change->to_status->value,
                        'toLabel' => (string) __($change->to_status->labelKey()),
                        'actor' => $change->actor_label,
                        'reason' => $change->reason,
                        'occurredAt' => $change->occurred_at->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'transitions' => array_map(
                    fn (OrderStatus $status): array => [
                        'value' => $status->value,
                        'label' => (string) __($status->labelKey()),
                    ],
                    $order->status->manualTransitions(),
                ),
            ],
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => (string) __($invoice->status->labelKey()),
                'balance' => $invoice->balance()->format(app()->getLocale()),
            ],
            'can' => [
                'update' => $this->actor->can('update', $order),
                'review' => $this->actor->can('review', $order),
                'invoice' => $this->actor->can('create', Invoice::class),
            ],
        ]);
    }

    public function updateStatus(OrderStatusRequest $request, Order $order, TransitionOrder $transition): RedirectResponse
    {
        $this->authorize('update', $order);

        $target = OrderStatus::from($request->string('status')->toString());

        // The screen only offers the transitions a human should make, and
        // the enum refuses the rest whatever the form says.
        $transition->handle($order, $target, $this->actor->model(), $request->input('reason'));

        return back()->with('status', __('ordering.orders.status_changed'));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function statuses(): array
    {
        return array_map(
            fn (OrderStatus $status): array => [
                'value' => $status->value,
                'label' => (string) __($status->labelKey()),
            ],
            OrderStatus::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'customer' => $order->customer?->displayName(),
            'customerId' => $order->customer_id,
            'status' => $order->status->value,
            'statusLabel' => (string) __($order->status->labelKey()),
            'currency' => $order->currency_code,
            'total' => $order->total->format(app()->getLocale()),
            'placedAt' => $order->placed_at?->toIso8601String(),
            'riskDecision' => $order->risk_decision?->value,
            'ipAddress' => $order->ip_address,
            // Where the money stands, read from the invoices this order
            // raised rather than from a second status on the order.
            'paymentStatus' => $this->paymentStatus($order),
            'paymentMethod' => $this->paymentMethod($order),
        ];
    }

    /**
     * Where the money stands on this order.
     *
     * Derived from the invoices it raised, never stored. `unbilled` is a
     * real answer and not a gap: an order in review has no invoice yet.
     */
    private function paymentStatus(Order $order): string
    {
        $order->loadMissing('invoices');

        if ($order->invoices->isEmpty()) {
            return 'unbilled';
        }

        if ($order->invoices->every(static fn (Invoice $invoice): bool => $invoice->status === InvoiceStatus::Paid)) {
            return 'paid';
        }

        if ($order->invoices->contains(static fn (Invoice $invoice): bool => $invoice->status === InvoiceStatus::Overdue)) {
            return 'overdue';
        }

        return 'unpaid';
    }

    private function paymentMethod(Order $order): ?string
    {
        $order->loadMissing('invoices.payments');

        foreach ($order->invoices as $invoice) {
            foreach ($invoice->payments as $payment) {
                return $payment->gateway;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(OrderItem $item, bool $withChildren = true): array
    {
        return [
            'id' => $item->id,
            'kind' => $item->kind->value,
            'name' => $item->name,
            'groupName' => $item->group_name,
            'cycle' => $item->billing_cycle?->value,
            'cycleLabel' => $item->billing_cycle === null
                ? null
                : (string) __('catalog.cycles.'.$item->billing_cycle->value),
            'quantity' => $item->quantity,
            'unitRecurring' => $item->unit_recurring->format(app()->getLocale()),
            'lineSetup' => $item->line_setup->isZero() ? null : $item->line_setup->format(app()->getLocale()),
            'lineDiscount' => $item->line_discount->isZero() ? null : $item->line_discount->format(app()->getLocale()),
            'lineTotal' => $item->line_total->format(app()->getLocale()),
            'domain' => $item->domain,
            'options' => $item->options
                ->map(fn (OrderItemOption $option): array => [
                    'group' => $option->group_name,
                    'label' => $option->label,
                    'amount' => $option->recurring->isZero() ? null : $option->recurring->format(app()->getLocale()),
                ])
                ->values()
                ->all(),
            // One level, because that is the shape an order has: a
            // product line with its addons under it. Recursing blindly
            // would reach for a third level that is never loaded, which
            // Laravel only reports once a line has two addons on it.
            'children' => $withChildren
                ? $item->children
                    ->map(fn (OrderItem $child): array => $this->item($child, withChildren: false))
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * Reasons translated for an operator. They never reach a customer.
     *
     * @return list<string>
     */
    private function riskReasons(Order $order): array
    {
        return array_values(array_map(
            static function (array $reason): string {
                /** @var array<string, scalar|null> $detail */
                $detail = $reason['detail'] ?? [];

                return (string) __('ordering.risk_reasons.'.(($reason['code'] ?? '')), $detail);
            },
            $order->risk_reasons ?? [],
        ));
    }
}
