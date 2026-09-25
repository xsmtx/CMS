<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Billing\AddTransaction;
use App\Application\Billing\AddTransactionRequest;
use App\Application\Billing\SearchTransactions;
use App\Application\Crm\SearchCustomers;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\TransactionKind;
use App\Domain\Shared\Money;
use App\Http\Controllers\Controller;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every movement of money, newest first.
 *
 * The ledger is the truth (ADR 0024): an invoice's paid amount is a cached
 * total of these rows and can be rebuilt from them. This screen is where an
 * accountant goes when the two disagree, which is the only time anybody
 * looks at a ledger.
 *
 * **Nothing here is editable and nothing ever will be.** A transaction is
 * append-only, so a correction is another transaction. What this screen
 * gained is the ability to *write* one — money that moved outside the
 * platform and that somebody with a bank statement in front of them has to
 * record — and that goes through `AddTransaction`, which goes through
 * `RecordPayment`, because one path settles an invoice.
 */
final class TransactionController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly SearchTransactions $search,
    ) {}

    public function index(Request $request): Response
    {
        if (! $this->actor->can('billing.invoices.view')) {
            throw new ForbiddenException(__('billing.errors.not_permitted'));
        }

        $criteria = $this->criteria($request);
        $transactions = $this->search->paginate($criteria);

        return Inertia::render('Admin/Billing/Transactions', [
            'transactions' => [
                'data' => array_map($this->row(...), $transactions->items()),
                'currentPage' => $transactions->currentPage(),
                'lastPage' => $transactions->lastPage(),
                'total' => $transactions->total(),
                'links' => $transactions->linkCollection()->all(),
            ],
            'flow' => $this->presentFlow($this->search->flow($criteria)),
            'filters' => $criteria,
            'kinds' => $this->kinds(),
            'gateways' => $this->gateways(),
            'canAdd' => $this->actor->can('billing.payments.record'),
        ]);
    }

    /**
     * The form for writing one down.
     *
     * The client picker and the client's open invoices are partial
     * reloads of this same screen rather than a JSON endpoint of their
     * own: the authorization, the boundary and the presenter are already
     * right here, and a second door into the same data is a second place
     * to get one of those three wrong.
     */
    public function create(Request $request, SearchCustomers $customers): Response
    {
        $this->assertCanRecord();

        $customer = $this->chosenCustomer($request);

        return Inertia::render('Admin/Billing/AddTransaction', [
            'gateways' => $this->gateways(),
            'currencies' => $this->search->currencies(),
            'defaultCurrency' => (string) config('platform.crm.default_currency', 'TRY'),
            'today' => CarbonImmutable::now()->toDateString(),
            'candidates' => $customers->lookup($request->string('q')->toString()),
            'chosen' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'currency' => $customer->currency_code,
            ],
            // What the money is most likely for. Shown as something to
            // tick rather than an id to copy: an operator retyping an
            // invoice number off another screen will eventually mistype
            // one, and a payment against the wrong invoice is a payment
            // somebody has to unpick by hand.
            'openInvoices' => $customer === null ? [] : $this->openInvoices($customer),
        ]);
    }

    public function store(Request $request, AddTransaction $transactions): RedirectResponse
    {
        $this->assertCanRecord();

        $data = $request->validate([
            'customer_id' => ['required', 'string'],
            'occurred_at' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'size:3'],
            'amount_in' => ['nullable', 'numeric', 'min:0'],
            'amount_out' => ['nullable', 'numeric', 'min:0'],
            'fees' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:512'],
            'reference' => ['nullable', 'string', 'max:191'],
            'invoice_ids' => ['nullable', 'string', 'max:512'],
            'to_credit' => ['nullable', 'boolean'],
            'gateway' => ['nullable', 'string', 'max:64'],
        ]);

        // Resolved through the model so the organization boundary answers
        // first: an id typed into a box is not proof of anything.
        $customer = Customer::query()->findOrFail((string) $data['customer_id']);
        $currency = strtoupper((string) $data['currency_code']);

        $written = $transactions->handle($customer, new AddTransactionRequest(
            amountIn: $this->money($data['amount_in'] ?? null, $currency),
            amountOut: $this->money($data['amount_out'] ?? null, $currency),
            occurredAt: CarbonImmutable::parse((string) $data['occurred_at']),
            invoiceIds: $this->invoiceIds($data['invoice_ids'] ?? null),
            toCreditBalance: (bool) ($data['to_credit'] ?? false),
            gateway: $data['gateway'] === null || $data['gateway'] === '' ? 'manual' : (string) $data['gateway'],
            reference: $data['reference'] ?? null,
            description: $data['description'] ?? null,
            fees: ($data['fees'] ?? null) === null ? null : $this->money($data['fees'], $currency),
            recordedBy: $this->actor->model()?->getAttribute('email'),
        ), $this->actor->model());

        return to_route('admin.transactions.index')
            ->with('status', __('billing.transactions.added', ['count' => count($written)]));
    }

    /**
     * The client on the form, resolved through the boundary.
     */
    private function chosenCustomer(Request $request): ?Customer
    {
        $id = $request->string('customer')->toString();

        if ($id === '') {
            return null;
        }

        // `where(...)->first()` rather than `find()`: `find()` on a
        // string is typed as possibly returning a collection, and the
        // obvious way out — `->find($id)?->first()` — silently forwards to
        // a fresh unscoped query and hands back somebody else's customer.
        return Customer::query()
            ->with(Customer::displayNameWith())
            ->where('id', $id)
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openInvoices(Customer $customer): array
    {
        $locale = app()->getLocale();

        return array_values(Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [
                InvoiceStatus::Unpaid->value,
                InvoiceStatus::Overdue->value,
                InvoiceStatus::PartiallyPaid->value,
            ])
            ->latest('issued_on')
            ->limit(25)
            ->get()
            ->map(static fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'statusLabel' => (string) __($invoice->status->labelKey()),
                'total' => $invoice->total->format($locale),
                'balance' => $invoice->balance()->format($locale),
                'currency' => $invoice->currency_code,
                'dueOn' => $invoice->due_on?->toDateString(),
            ])
            ->all());
    }

    /**
     * An amount an operator typed, in minor units.
     *
     * The float exists for one expression and never reaches a column:
     * money is integer minor units everywhere it is stored, compared or
     * sent (ADR: money is never a float).
     */
    private function money(mixed $value, string $currency): Money
    {
        if ($value === null || $value === '') {
            return Money::zero($currency);
        }

        return Money::ofMinor(
            (int) round(((float) str_replace(',', '.', (string) $value)) * 100),
            $currency,
        );
    }

    /**
     * @return list<string>
     */
    private function invoiceIds(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            trim(...),
            explode(',', $value),
        ), static fn (string $one): bool => $one !== ''));
    }

    /**
     * @return array<string, string|null>
     */
    private function criteria(Request $request): array
    {
        $keys = [
            'kind', 'direction', 'client', 'reference', 'invoice',
            'gateway', 'description', 'from', 'to', 'amount',
        ];

        $criteria = [];

        foreach ($keys as $key) {
            $value = trim($request->string($key)->toString());

            $criteria[$key] = $value === '' ? null : $value;
        }

        return $criteria;
    }

    /**
     * Minor units into something a page can print.
     *
     * The totals cross the wire as a formatted string and a minor-unit
     * integer, never as a decimal number: a float in a JSON payload is a
     * float in the browser.
     *
     * @param  array{in: list<array{label: string, value: int}>, out: list<array{label: string, value: int}>, totalIn: int, totalOut: int, currency: string}  $flow
     * @return array<string, mixed>
     */
    private function presentFlow(array $flow): array
    {
        $locale = app()->getLocale();

        return [
            'in' => $flow['in'],
            'out' => $flow['out'],
            'totalIn' => Money::ofMinor($flow['totalIn'], $flow['currency'])->format($locale),
            'totalOut' => Money::ofMinor($flow['totalOut'], $flow['currency'])->format($locale),
            'net' => Money::ofMinor($flow['totalIn'] - $flow['totalOut'], $flow['currency'])->format($locale),
            'currency' => $flow['currency'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function kinds(): array
    {
        return array_values(array_map(
            static fn (TransactionKind $case): array => [
                'value' => $case->value,
                'label' => (string) __($case->labelKey()),
            ],
            TransactionKind::cases(),
        ));
    }

    /**
     * The gateways this installation actually has.
     *
     * Read from the registry rather than hard-coded: one missing its
     * credentials never gets registered, so it is never offered and then
     * found not to work.
     *
     * @return list<array{value: string, label: string}>
     */
    private function gateways(): array
    {
        $registry = app(GatewayRegistry::class);

        return array_values(array_map(
            static fn (string $key): array => [
                'value' => $key,
                'label' => (string) __('billing.gateways.'.$key),
            ],
            $registry->keys(),
        ));
    }

    private function assertCanRecord(): void
    {
        if (! $this->actor->can('billing.payments.record')) {
            throw new ForbiddenException(__('billing.errors.not_permitted'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'kind' => $transaction->kind->value,
            'kindLabel' => (string) __($transaction->kind->labelKey()),
            // A transaction is always positive; the kind decides direction
            // (ADR 0024). The screen shows the sign rather than the row
            // storing one.
            'increasesBalance' => $transaction->kind->increasesPaid(),
            'amount' => $transaction->amount->format(app()->getLocale()),
            'fees' => $transaction->fees->isPositive()
                ? $transaction->fees->format(app()->getLocale())
                : null,
            'creditBalance' => $transaction->credit_balance->format(app()->getLocale()),
            'customer' => $transaction->customer?->displayName(),
            'customerId' => $transaction->customer_id,
            'invoice' => $transaction->invoice?->number,
            'invoiceId' => $transaction->invoice_id,
            'description' => $transaction->description,
            'gateway' => $transaction->gateway,
            'gatewayLabel' => $transaction->gateway === null
                ? null
                : (string) __('billing.gateways.'.$transaction->gateway),
            'reference' => $transaction->reference,
            // Who said so, for a payment somebody recorded by hand. A
            // gateway's payments carry the gateway's own reference instead.
            'recordedBy' => $transaction->recorded_by,
            'occurredAt' => $transaction->occurred_at->toIso8601String(),
        ];
    }
}
