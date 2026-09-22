<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Shared\Money;
use App\Http\Controllers\Controller;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Gateways\ManualGateway;
use App\Infrastructure\Billing\Models\CreditNote;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Billing\Models\Payment;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's invoices.
 *
 * Reads the same rows the admin screen reads, narrowed to this customer and
 * presented with less: an invoice carries internal notes and an operator's
 * reason for a credit note, and neither is the customer's business.
 *
 * A draft is not an invoice yet and is not listed. Nothing has been claimed
 * from the customer until it is issued.
 */
final class InvoiceController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
        private readonly GatewayRegistry $gateways,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeBilling();

        $status = $request->string('status')->toString();

        $invoices = $this->customer
            ->owned(Invoice::query())
            ->whereNot('status', InvoiceStatus::Draft->value)
            ->when(
                InvoiceStatus::tryFrom($status) instanceof InvoiceStatus,
                fn ($query) => $query->where('status', $status),
            )
            ->latest('issued_on')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Client/Billing/Invoices', [
            'invoices' => [
                'data' => array_map($this->row(...), $invoices->items()),
                'currentPage' => $invoices->currentPage(),
                'lastPage' => $invoices->lastPage(),
                'total' => $invoices->total(),
            ],
            'filters' => ['status' => $status === '' ? null : $status],
            'statuses' => $this->statuses(),
            'outstanding' => $this->outstanding(),
        ]);
    }

    public function show(string $number): Response
    {
        $this->authorizeBilling();

        $invoice = $this->customer->find(
            Invoice::query()
                ->where('number', $number)
                ->whereNot('status', InvoiceStatus::Draft->value),
        );

        $invoice->load(['items', 'payments', 'creditNotes']);

        return Inertia::render('Client/Billing/Invoice', [
            'invoice' => [
                ...$this->row($invoice),
                'subtotal' => $invoice->subtotal->format(app()->getLocale()),
                'discount' => $invoice->discount->isZero()
                    ? null
                    : $invoice->discount->format(app()->getLocale()),
                'tax' => $invoice->tax->format(app()->getLocale()),
                'paid' => $invoice->paid->format(app()->getLocale()),
                'issuedOn' => $invoice->issued_on?->toDateString(),
                'billTo' => array_values(array_filter([
                    $invoice->bill_to_name,
                    $invoice->bill_to_company,
                    $invoice->bill_to_address,
                    $invoice->bill_to_tax_id,
                ])),
                'items' => $invoice->items
                    ->map(fn (InvoiceItem $item): array => [
                        'id' => $item->id,
                        'description' => $item->description,
                        'detail' => $item->detail,
                        'quantity' => $item->quantity,
                        'amount' => $item->line_amount->format(app()->getLocale()),
                    ])
                    ->values()
                    ->all(),
                'payments' => $invoice->payments
                    // A failed attempt is the customer's own card being
                    // declined; hiding it only makes them try again blind.
                    ->map(fn (Payment $payment): array => [
                        'id' => $payment->id,
                        'gateway' => $payment->gateway,
                        'amount' => $payment->amount->format(app()->getLocale()),
                        'status' => (string) __('billing.payment_statuses.'.$payment->status->value),
                        'receivedAt' => $payment->received_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'creditNotes' => $invoice->creditNotes
                    // The number and the amount. Why an operator issued it
                    // is an internal note.
                    ->map(fn (CreditNote $note): array => [
                        'id' => $note->id,
                        'number' => $note->number,
                        'amount' => $note->amount->format(app()->getLocale()),
                        'issuedOn' => $note->issued_on?->toDateString(),
                    ])
                    ->values()
                    ->all(),
            ],
            'gateways' => $this->gatewayOptions($invoice),
            'can' => ['pay' => $this->actor->can('portal.billing.pay')],
        ]);
    }

    /**
     * What is owed, per currency.
     *
     * Grouped rather than summed: adding EUR to TRY at a rate invented for
     * a heading is how a customer ends up arguing about a number nobody can
     * reproduce.
     *
     * @return list<array{currency: string, amount: string}>
     */
    private function outstanding(): array
    {
        $totals = [];

        $invoices = $this->customer
            ->owned(Invoice::query())
            ->whereIn('status', InvoiceStatus::owed())
            ->get();

        foreach ($invoices as $invoice) {
            $balance = $invoice->balance();
            $code = $balance->currency->code;

            $totals[$code] = isset($totals[$code])
                ? $totals[$code]->plus($balance)
                : $balance;
        }

        return array_values(array_map(
            static fn (Money $amount): array => [
                'currency' => $amount->currency->code,
                'amount' => $amount->format(app()->getLocale()),
            ],
            $totals,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'statusLabel' => (string) __($invoice->status->labelKey()),
            'total' => $invoice->total->format(app()->getLocale()),
            'balance' => $invoice->balance()->format(app()->getLocale()),
            'isOwed' => $invoice->status->isOwed(),
            'dueOn' => $invoice->due_on?->toDateString(),
        ];
    }

    /**
     * How they may pay, as the installation has actually configured it: a
     * gateway without credentials was never registered, and one that does
     * not take this currency is not offered.
     *
     * @return list<array{value: string, label: string, instructions: string|null}>
     */
    private function gatewayOptions(Invoice $invoice): array
    {
        if (! $invoice->status->isOwed() || ! $this->actor->can('portal.billing.pay')) {
            return [];
        }

        return array_values(array_map(
            static fn (PaymentGateway $gateway): array => [
                'value' => $gateway->key(),
                'label' => (string) __('billing.gateways.'.$gateway->key()),
                'instructions' => $gateway instanceof ManualGateway && $gateway->instructions() !== ''
                    ? $gateway->instructions()
                    : null,
            ],
            $this->gateways->forCurrency($invoice->currency_code),
        ));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statuses(): array
    {
        return array_values(array_map(
            static fn (InvoiceStatus $status): array => [
                'value' => $status->value,
                'label' => (string) __($status->labelKey()),
            ],
            array_filter(
                InvoiceStatus::cases(),
                static fn (InvoiceStatus $status): bool => $status !== InvoiceStatus::Draft,
            ),
        ));
    }

    private function authorizeBilling(): void
    {
        if (! $this->actor->can('portal.billing.view')) {
            throw new ForbiddenException(__('billing.not_permitted'));
        }
    }
}
