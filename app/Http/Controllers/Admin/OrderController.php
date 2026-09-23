<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Crm\SearchCustomers;
use App\Application\Ordering\OrderLineRequest;
use App\Application\Ordering\PlaceOrderForCustomer;
use App\Application\Ordering\PlaceOrderForCustomerRequest;
use App\Application\Ordering\SearchOrders;
use App\Application\Ordering\TransitionOrder;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Domains\DomainOrderType;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Shared\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ordering\OrderStatusRequest;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Catalog\Models\Product;
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

    /**
     * The form for taking an order over the phone.
     *
     * The catalogue is sent with the page rather than searched, because an
     * operator taking an order has to see what is on sale — a product they
     * cannot find is a product they do not sell. The client picker is a
     * partial reload of this same screen, like every other picker here.
     */
    public function create(Request $request, SearchCustomers $customers): Response
    {
        $this->authorize('create', Order::class);

        $customer = $this->chosenCustomer($request);
        $currency = $customer instanceof Customer
            ? $customer->currency_code
            : strtoupper((string) config('platform.crm.default_currency', 'TRY'));

        return Inertia::render('Admin/Orders/Create', [
            'candidates' => $customers->lookup($request->string('q')->toString()),
            'chosen' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'email' => $customer->primaryContact?->email,
                'currency' => $customer->currency_code,
            ],
            'currency' => $currency,
            'products' => $this->sellableProducts($currency),
            'gateways' => $this->gateways(),
            'domainActions' => array_values(array_map(
                static fn (DomainOrderType $case): array => [
                    'value' => $case->value,
                    'label' => (string) __($case->labelKey()),
                ],
                [DomainOrderType::Register, DomainOrderType::Transfer],
            )),
            // A preview only. The summary on the right adds up the way
            // the customer's will, but the number that gets charged is
            // worked out server side by the tax contract when the order is
            // placed — core never implements a country's tax rules
            // (ADR 0022), and a driver of `none` charges nothing.
            'taxRatePercent' => config('platform.tax.driver') === 'flat'
                ? (string) config('platform.tax.flat.rate', '0')
                : '0',
            'taxName' => (string) config('platform.tax.flat.name', 'VAT'),
        ]);
    }

    public function store(Request $request, PlaceOrderForCustomer $orders): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'customer_id' => ['required', 'ulid', 'exists:customers,id'],
            'lines' => ['required_without:domain_name', 'array', 'max:20'],
            'lines.*.product_id' => ['required', 'ulid'],
            'lines.*.billing_cycle' => ['required', 'string'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'lines.*.domain' => ['nullable', 'string', 'max:253'],
            'lines.*.price_override' => ['nullable', 'numeric', 'min:0'],
            'promotion_code' => ['nullable', 'string', 'max:64'],
            'gateway' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['nullable', 'boolean'],
            'generate_invoice' => ['nullable', 'boolean'],
            'send_email' => ['nullable', 'boolean'],
            'domain_action' => ['nullable', 'string'],
            'domain_name' => ['nullable', 'string', 'max:253'],
            'domain_years' => ['nullable', 'integer', 'min:1', 'max:10'],
            'domain_addons' => ['nullable', 'array'],
            'domain_addons.*' => ['string', 'in:dns_management,email_forwarding,id_protection'],
            'domain_registration_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $order = $orders->handle(new PlaceOrderForCustomerRequest(
            customerId: (string) $data['customer_id'],
            lines: $this->linesFrom($data['lines'] ?? []),
            promotionCode: $data['promotion_code'] ?? null,
            gateway: $data['gateway'] ?? null,
            notes: $data['notes'] ?? null,
            confirm: (bool) ($data['confirm'] ?? true),
            generateInvoice: (bool) ($data['generate_invoice'] ?? false),
            sendEmail: (bool) ($data['send_email'] ?? true),
            domainAction: DomainOrderType::tryFrom((string) ($data['domain_action'] ?? '')),
            domainName: $data['domain_name'] ?? null,
            domainYears: (int) ($data['domain_years'] ?? 1),
            domainAddons: array_values($data['domain_addons'] ?? []),
            domainRegistrationOverrideMinor: $this->minor($data['domain_registration_price'] ?? null),
        ), $this->actor->model());

        return to_route('admin.orders.show', $order)
            ->with('status', __('ordering.orders.created', ['number' => $order->number]));
    }

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
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<OrderLineRequest>
     */
    private function linesFrom(array $lines): array
    {
        $requests = [];

        foreach ($lines as $line) {
            $cycle = BillingCycle::tryFrom((string) ($line['billing_cycle'] ?? ''));

            if (! $cycle instanceof BillingCycle) {
                continue;
            }

            $requests[] = new OrderLineRequest(
                productId: (string) $line['product_id'],
                cycle: $cycle,
                quantity: max((int) ($line['quantity'] ?? 1), 1),
                domain: ($line['domain'] ?? '') === '' ? null : (string) $line['domain'],
                priceOverrideMinor: $this->minor($line['price_override'] ?? null),
            );
        }

        return $requests;
    }

    /**
     * An amount an operator typed, in minor units.
     *
     * The float exists for one expression and never reaches a column.
     * Null stays null: no override is not the same as an override of zero.
     */
    private function minor(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(((float) str_replace(',', '.', (string) $value)) * 100);
    }

    private function chosenCustomer(Request $request): ?Customer
    {
        $id = $request->string('customer')->toString();

        if ($id === '') {
            return null;
        }

        return Customer::query()
            ->with(Customer::displayNameWith())
            ->where('id', $id)
            ->first();
    }

    /**
     * What is on sale, in the currency this order is in.
     *
     * A product with no price in that currency is left out rather than
     * shown and then refused: an operator should not be able to pick
     * something the cart will not accept.
     *
     * @return list<array<string, mixed>>
     */
    private function sellableProducts(string $currency): array
    {
        $locale = app()->getLocale();
        $rows = [];

        $products = Product::query()
            ->with(['prices', 'group'])
            ->orderBy('name')
            ->get();

        foreach ($products as $product) {
            if (! $product->status->isOrderable() || $product->isSoldOut()) {
                continue;
            }

            $cycles = [];

            foreach (BillingCycle::cases() as $cycle) {
                $recurring = $product->recurringFor($cycle, $currency);

                if ($recurring === null) {
                    continue;
                }

                $setup = $product->setupFor($cycle, $currency);

                $cycles[] = [
                    'value' => $cycle->value,
                    'label' => (string) __($cycle->labelKey()),
                    'recurringMinor' => $recurring->minorUnits,
                    'recurring' => $recurring->format($locale),
                    'setupMinor' => $setup instanceof Money ? $setup->minorUnits : 0,
                    'setup' => $setup?->format($locale),
                ];
            }

            if ($cycles === []) {
                continue;
            }

            $rows[] = [
                'id' => $product->id,
                'name' => $product->name,
                'group' => $product->group?->name,
                'requiresDomain' => $product->requires_domain,
                'cycles' => $cycles,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function gateways(): array
    {
        return array_values(array_map(
            static fn (string $key): array => [
                'value' => $key,
                'label' => (string) __('billing.gateways.'.$key),
            ],
            app(GatewayRegistry::class)->keys(),
        ));
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
