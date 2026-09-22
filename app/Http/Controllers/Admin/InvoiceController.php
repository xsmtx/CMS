<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Billing\CreateInvoiceFromOrder;
use App\Application\Billing\IssueInvoice;
use App\Application\Billing\Ledger;
use App\Application\Billing\TransitionInvoice;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Shared\Money;
use App\Http\Controllers\Controller;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Models\CreditNote;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Ordering\Models\Order;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class InvoiceController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly Ledger $ledger,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Invoice::class);

        $status = $request->string('status')->toString();

        $invoices = Invoice::query()
            ->with('customer')
            ->when(
                InvoiceStatus::tryFrom($status) instanceof InvoiceStatus,
                fn ($query) => $query->where('status', $status),
            )
            ->latest('issued_on')
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Invoices/Index', [
            'invoices' => [
                'data' => array_map(fn (Invoice $invoice): array => $this->row($invoice), $invoices->items()),
                'currentPage' => $invoices->currentPage(),
                'lastPage' => $invoices->lastPage(),
                'total' => $invoices->total(),
            ],
            'filters' => ['status' => $status === '' ? null : $status],
            'statuses' => self::statuses(),
            'owed' => $this->owedSummary(),
        ]);
    }

    public function show(Invoice $invoice, GatewayRegistry $gateways): Response
    {
        $this->authorize('view', $invoice);

        $invoice->load(['customer', 'order', 'items', 'payments', 'transactions', 'creditNotes']);

        return Inertia::render('Admin/Invoices/Show', [
            'invoice' => [
                ...$this->row($invoice),
                'billTo' => [
                    'name' => $invoice->bill_to_name,
                    'company' => $invoice->bill_to_company,
                    'taxId' => $invoice->bill_to_tax_id,
                    'address' => $invoice->bill_to_address,
                    'country' => $invoice->bill_to_country,
                    'email' => $invoice->bill_to_email,
                ],
                'subtotal' => $invoice->subtotal->format(app()->getLocale()),
                'discount' => $invoice->discount->isZero() ? null : $invoice->discount->format(app()->getLocale()),
                'tax' => $invoice->tax->isZero() ? null : $invoice->tax->format(app()->getLocale()),
                'taxBreakdown' => $invoice->tax_breakdown ?? [],
                'orderNumber' => $invoice->order?->number,
                'orderId' => $invoice->order_id,
                'notes' => $invoice->notes,
                'terms' => $invoice->terms,
                'items' => $invoice->items
                    ->map(fn (InvoiceItem $item): array => [
                        'id' => $item->id,
                        'description' => $item->description,
                        'detail' => $item->detail,
                        'quantity' => $item->quantity,
                        'unitAmount' => $item->unit_amount->format(app()->getLocale()),
                        'lineAmount' => $item->line_amount->format(app()->getLocale()),
                        'discount' => $item->discount->isZero() ? null : $item->discount->format(app()->getLocale()),
                    ])
                    ->values()
                    ->all(),
                'payments' => $invoice->payments
                    ->map(fn (Payment $payment): array => [
                        'id' => $payment->id,
                        'gateway' => (string) __('billing.gateways.'.$payment->gateway),
                        'status' => $payment->status->value,
                        'statusLabel' => (string) __($payment->status->labelKey()),
                        'amount' => $payment->amount->format(app()->getLocale()),
                        'refunded' => $payment->refunded->isZero()
                            ? null
                            : $payment->refunded->format(app()->getLocale()),
                        'refundable' => $payment->refundable()->minorUnits,
                        'reference' => $payment->reference,
                        'receivedAt' => $payment->received_at?->toIso8601String(),
                        'note' => $payment->note,
                    ])
                    ->values()
                    ->all(),
                'ledger' => $invoice->transactions
                    ->map(fn (Transaction $transaction): array => [
                        'kind' => $transaction->kind->value,
                        'kindLabel' => (string) __($transaction->kind->labelKey()),
                        'amount' => $transaction->amount->format(app()->getLocale()),
                        'increases' => $transaction->kind->increasesPaid(),
                        'description' => $transaction->description,
                        'occurredAt' => $transaction->occurred_at->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'creditNotes' => $invoice->creditNotes
                    ->map(fn (CreditNote $note): array => [
                        'number' => $note->number,
                        'amount' => $note->amount->format(app()->getLocale()),
                        'reason' => $note->reason,
                        'issuedOn' => $note->issued_on->toDateString(),
                    ])
                    ->values()
                    ->all(),
                'creditBalance' => $invoice->customer === null
                    ? null
                    : $this->ledger->creditBalance($invoice->customer, $invoice->currency_code)
                        ->format(app()->getLocale()),
                'creditBalanceMinor' => $invoice->customer === null
                    ? 0
                    : $this->ledger->creditBalance($invoice->customer, $invoice->currency_code)->minorUnits,
            ],
            'gateways' => array_values(array_map(
                static fn (string $key): array => [
                    'value' => $key,
                    'label' => (string) __('billing.gateways.'.$key),
                ],
                $gateways->keys(),
            )),
            'can' => [
                'update' => $this->actor->can('update', $invoice),
                'recordPayment' => $this->actor->can('recordPayment', $invoice),
                'refund' => $this->actor->can('refund', $invoice),
                'credit' => $this->actor->can('credit', $invoice),
            ],
        ]);
    }

    /**
     * Raise a draft invoice for an order that has none.
     */
    public function storeForOrder(Order $order, CreateInvoiceFromOrder $create): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $invoice = $create->handle($order, $this->actor->model());

        return to_route('admin.invoices.show', $invoice)
            ->with('status', __('billing.invoices.created', ['number' => $invoice->number]));
    }

    public function issue(Invoice $invoice, IssueInvoice $issue): RedirectResponse
    {
        $this->authorize('update', $invoice);

        $issued = $issue->handle($invoice, $this->actor->model());

        return back()->with('status', __('billing.invoices.issued_message', ['number' => $issued->number]));
    }

    public function cancel(Request $request, Invoice $invoice, TransitionInvoice $transition): RedirectResponse
    {
        $this->authorize('update', $invoice);

        $transition->handle($invoice, InvoiceStatus::Cancelled, $this->actor->model(), $request->input('reason'));

        return back()->with('status', __('billing.invoices.cancelled_message'));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function statuses(): array
    {
        return array_map(
            fn (InvoiceStatus $status): array => [
                'value' => $status->value,
                'label' => (string) __($status->labelKey()),
            ],
            InvoiceStatus::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'customer' => $invoice->customer?->displayName(),
            'customerId' => $invoice->customer_id,
            'status' => $invoice->status->value,
            'statusLabel' => (string) __($invoice->status->labelKey()),
            'currency' => $invoice->currency_code,
            'total' => $invoice->total->format(app()->getLocale()),
            'paid' => $invoice->paid->format(app()->getLocale()),
            'balance' => $invoice->balance()->format(app()->getLocale()),
            'balanceMinor' => $invoice->balance()->minorUnits,
            'issuedOn' => $invoice->issued_on?->toDateString(),
            'dueOn' => $invoice->due_on?->toDateString(),
            'isPastDue' => $invoice->isPastDue(),
            'isProforma' => $invoice->is_proforma,
        ];
    }

    /**
     * What is outstanding, for the header of the list.
     *
     * Grouped by currency rather than summed: adding euros to lira is the
     * mistake this platform refuses everywhere else.
     *
     * @return list<array{currency: string, amount: string, count: int}>
     */
    private function owedSummary(): array
    {
        $rows = Invoice::query()
            ->owed()
            ->selectRaw('currency_code, SUM(total_minor - paid_minor) as outstanding, COUNT(*) as invoices')
            ->groupBy('currency_code')
            ->get();

        return array_values($rows
            ->map(fn (Invoice $row): array => [
                'currency' => (string) $row->getAttribute('currency_code'),
                'amount' => Money::ofMinor(
                    (int) $row->getAttribute('outstanding'),
                    (string) $row->getAttribute('currency_code'),
                )->format(app()->getLocale()),
                'count' => (int) $row->getAttribute('invoices'),
            ])
            ->all());
    }
}
