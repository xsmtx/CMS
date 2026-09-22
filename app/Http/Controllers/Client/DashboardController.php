<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Application\Billing\Ledger;
use App\Domain\Billing\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Ordering\Models\Order;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client area landing page.
 *
 * Six things a customer opens the portal to find out. Three of them have
 * data behind them today; the rest arrive with Phases 6, 7 and 8. Those are
 * **absent**, not rendered as empty tables — a dashboard full of "no
 * results" panels teaches a customer that the page is not worth opening.
 *
 * Everything here is scoped by `CurrentCustomer` and gated by the same
 * permissions as the screens it summarises: a portal member who cannot see
 * billing does not get a total owed on the front page instead.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
        private readonly Ledger $ledger,
    ) {}

    public function __invoke(): Response
    {
        $canBilling = $this->actor->can('portal.billing.view');
        $canOrders = $this->actor->can('portal.orders.view');

        return Inertia::render('Client/Dashboard', [
            'name' => $this->customer->contact()->first_name,
            'unpaid' => $canBilling ? $this->unpaid() : [],
            'credit' => $canBilling ? $this->credit() : null,
            'orders' => $canOrders ? $this->orders() : [],
            'can' => ['billing' => $canBilling, 'orders' => $canOrders],
        ]);
    }

    /**
     * What is owed, soonest first — which is the order a customer cares
     * about and the order a dunning run will work through.
     *
     * @return list<array<string, mixed>>
     */
    private function unpaid(): array
    {
        return array_values($this->customer
            ->owned(Invoice::query())
            ->whereIn('status', InvoiceStatus::owed())
            ->orderByRaw('due_on IS NULL, due_on ASC')
            ->limit(5)
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'number' => $invoice->number,
                'balance' => $invoice->balance()->format(app()->getLocale()),
                'dueOn' => $invoice->due_on?->toDateString(),
                'isPastDue' => $invoice->isPastDue(),
            ])
            ->all());
    }

    /**
     * @return array{balance: string, isZero: bool}|null
     */
    private function credit(): ?array
    {
        $customer = $this->customer->model();
        $balance = $this->ledger->creditBalance($customer, $customer->currency_code);

        // A zero balance is not news. The panel appears when there is
        // something in it.
        return $balance->isZero() ? null : [
            'balance' => $balance->format(app()->getLocale()),
            'isZero' => false,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orders(): array
    {
        return array_values($this->customer
            ->owned(Order::query())
            ->whereNot('status', 'draft')
            ->latest('placed_at')
            ->limit(5)
            ->get()
            ->map(fn (Order $order): array => [
                'number' => $order->number,
                'status' => $order->status->value,
                'statusLabel' => (string) __($order->status->labelKey()),
                'total' => $order->total->format(app()->getLocale()),
                'placedAt' => $order->placed_at?->toIso8601String(),
            ])
            ->all());
    }
}
