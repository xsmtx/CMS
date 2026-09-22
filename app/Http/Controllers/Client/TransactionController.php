<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Application\Billing\Ledger;
use App\Domain\Billing\TransactionKind;
use App\Http\Controllers\Controller;
use App\Infrastructure\Billing\Models\Transaction;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ledger, as the customer sees it.
 *
 * Every row that moved money on this account, in the order it happened,
 * with the running credit balance beside it. This is the screen that
 * answers "where did my credit go", and it answers it from the rows rather
 * than from a cached column — the same rule the rest of billing runs on
 * ([ADR 0024](../../docs/adr/0024-the-ledger-is-the-truth.md)).
 *
 * An operator's reason for an adjustment is an internal note and does not
 * appear. What the row was, what it moved and against which document does.
 */
final class TransactionController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
        private readonly Ledger $ledger,
    ) {}

    public function index(): Response
    {
        if (! $this->actor->can('portal.billing.view')) {
            throw new ForbiddenException(__('billing.not_permitted'));
        }

        $customer = $this->customer->model();

        $transactions = $this->customer
            ->owned(Transaction::query())
            ->with('invoice:id,number')
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(30);

        return Inertia::render('Client/Billing/Transactions', [
            'transactions' => [
                'data' => array_map(
                    fn (Transaction $transaction): array => [
                        'id' => $transaction->id,
                        'kind' => $transaction->kind->value,
                        'kindLabel' => (string) __('billing.transaction_kinds.'.$transaction->kind->value),
                        'increasesPaid' => $transaction->kind->increasesPaid(),
                        'amount' => $transaction->amount->format(app()->getLocale()),
                        'creditBalance' => $transaction->credit_balance->format(app()->getLocale()),
                        'invoiceNumber' => $transaction->invoice?->number,
                        'occurredAt' => $transaction->occurred_at->toIso8601String(),
                    ],
                    $transactions->items(),
                ),
                'currentPage' => $transactions->currentPage(),
                'lastPage' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
            'credit' => [
                'balance' => $this->ledger
                    ->creditBalance($customer, $customer->currency_code)
                    ->format(app()->getLocale()),
                'currency' => $customer->currency_code,
            ],
            'kinds' => array_values(array_map(
                static fn (TransactionKind $kind): array => [
                    'value' => $kind->value,
                    'label' => (string) __('billing.transaction_kinds.'.$kind->value),
                ],
                TransactionKind::cases(),
            )),
        ]);
    }
}
