<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\TransactionKind;
use App\Http\Controllers\Controller;
use App\Infrastructure\Billing\Models\Transaction;
use App\Infrastructure\Crm\Models\Customer;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
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
 * Read-only, and it will stay read-only. A transaction is append-only, so
 * there is nothing here to edit; a correction is another transaction, made
 * by the thing that caused it.
 */
final class TransactionController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Request $request): Response
    {
        if (! $this->actor->can('billing.invoices.view')) {
            throw new ForbiddenException(__('billing.errors.not_permitted'));
        }

        $kind = TransactionKind::tryFrom($request->string('kind')->toString());

        $transactions = Transaction::query()
            ->with([...Customer::displayNameWith('customer'), 'invoice:id,number'])
            ->when($kind instanceof TransactionKind, fn ($query) => $query->where('kind', $kind?->value))
            ->latest('occurred_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Billing/Transactions', [
            'transactions' => [
                'data' => array_map($this->row(...), $transactions->items()),
                'currentPage' => $transactions->currentPage(),
                'lastPage' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
            'filters' => ['kind' => $kind?->value],
            'kinds' => array_values(array_map(
                static fn (TransactionKind $case): array => [
                    'value' => $case->value,
                    'label' => (string) __($case->labelKey()),
                ],
                TransactionKind::cases(),
            )),
        ]);
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
            'creditBalance' => $transaction->credit_balance->format(app()->getLocale()),
            'customer' => $transaction->customer?->displayName(),
            'customerId' => $transaction->customer_id,
            'invoice' => $transaction->invoice?->number,
            'invoiceId' => $transaction->invoice_id,
            'description' => $transaction->description,
            // Who said so, for a payment somebody recorded by hand. A
            // gateway's payments carry the gateway's own reference instead.
            'recordedBy' => $transaction->recorded_by,
            'occurredAt' => $transaction->occurred_at->toIso8601String(),
        ];
    }
}
