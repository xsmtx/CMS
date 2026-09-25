<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Application\Billing\Ledger;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Domains\DomainStatus;
use App\Domain\Provisioning\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client area landing page.
 *
 * What is running, what is owed, and what is about to expire. The services
 * and the domains waited for Phases 6 and 7 and were then left behind: a
 * customer whose whole account is one hosting plan opened this page and saw
 * two empty billing panels and no mention of the thing they bought.
 *
 * A panel is absent when the contact may not see what is in it, not when it
 * happens to be empty — an empty panel says "nothing here yet", which is an
 * answer, and a missing one says nothing at all.
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
        $canServices = $this->actor->can('portal.services.view');
        $canDomains = $this->actor->can('portal.domains.view');

        return Inertia::render('Client/Dashboard', [
            'name' => $this->customer->contact()->first_name,
            'unpaid' => $canBilling ? $this->unpaid() : [],
            'credit' => $canBilling ? $this->credit() : null,
            'orders' => $canOrders ? $this->orders() : [],
            'services' => $canServices ? $this->services() : [],
            'domains' => $canDomains ? $this->domains() : [],
            'can' => [
                'billing' => $canBilling,
                'orders' => $canOrders,
                'services' => $canServices,
                'domains' => $canDomains,
            ],
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
     * What is running, soonest renewal first.
     *
     * Terminated services are left out — a customer looking at their account
     * wants what they have, and what they used to have is in the orders.
     *
     * @return list<array<string, mixed>>
     */
    private function services(): array
    {
        return array_values($this->customer
            ->owned(Service::query())
            ->whereNot('status', ServiceStatus::Terminated->value)
            ->orderByRaw('next_due_on IS NULL, next_due_on ASC')
            ->limit(5)
            ->get()
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'domain' => $service->domain,
                'status' => $service->status->value,
                'statusLabel' => (string) __($service->status->labelKey()),
                'nextDueOn' => $service->next_due_on?->toDateString(),
            ])
            ->all());
    }

    /**
     * Domains, soonest expiry first, which is the one that matters: a domain
     * nobody renewed is the outage a customer cannot undo afterwards.
     *
     * @return list<array<string, mixed>>
     */
    private function domains(): array
    {
        return array_values($this->customer
            ->owned(Domain::query())
            ->whereNotIn('status', [DomainStatus::Deleted->value, DomainStatus::Cancelled->value])
            ->orderByRaw('expires_on IS NULL, expires_on ASC')
            ->limit(5)
            ->get()
            ->map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'name' => $domain->name,
                'status' => $domain->status->value,
                'statusLabel' => (string) __($domain->status->labelKey()),
                'expiresOn' => $domain->expires_on?->toDateString(),
            ])
            ->all());
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
