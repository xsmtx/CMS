<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Application\Infrastructure\ImpactSummary;
use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\SlaCredit;
use App\Infrastructure\Resources\Models\ResourceNode;

/**
 * Who an incident actually hit, and which invoice of theirs to credit.
 *
 * `IncidentImpact` froze *how many*, which is what a screen reads at a
 * glance; this answers *who*, which is what a credit needs. They are kept
 * apart deliberately: the frozen figure must never move, and this one must be
 * current — an operator raising a credit a week later wants the customer's
 * latest issued invoice, not the one that existed the night of the outage.
 *
 * **It is the one thing in this conversation only this platform can do.**
 * Every monitoring system can say a server was down; none of them knows that
 * eleven services on it belonged to nine customers, or which invoice covers
 * the period. The path is the graph's: the incident's alerts name nodes, the
 * nodes contain services, and a service belongs to a customer.
 *
 * A customer with no issued invoice appears all the same, with none offered.
 * Dropping them would be this platform deciding somebody is owed nothing
 * because of a billing accident, and the operator is the one who decides.
 */
final readonly class AffectedCustomers
{
    public function __construct(private ImpactSummary $impact) {}

    /**
     * @return list<array{
     *     customer: Customer,
     *     services: int,
     *     invoices: list<Invoice>,
     *     credited: SlaCredit|null,
     * }>
     */
    public function forIncident(Incident $incident, int $invoicesPerCustomer = 6): array
    {
        $keys = $incident->alerts()->pluck('subject_key')->unique()->all();

        if ($keys === []) {
            return [];
        }

        $nodes = ResourceNode::query()
            ->whereIn('node_key', $keys)
            ->whereNull('retired_at')
            ->get();

        $serviceIds = [];

        foreach ($nodes as $node) {
            foreach ($this->impact->serviceIdsUnder($node) as $id) {
                $serviceIds[$id] = true;
            }
        }

        if ($serviceIds === []) {
            return [];
        }

        // Only the columns this needs. Safe here for the reason `ImpactSummary`
        // states: nothing renders a customer name from *these* rows — the
        // names come from the customers loaded below, with
        // `displayNameWith()`, which is the rule that bit orders and invoices.
        $services = Service::query()
            ->whereIn('id', array_keys($serviceIds))
            ->get(['id', 'customer_id']);

        $counts = [];

        foreach ($services as $service) {
            $counts[$service->customer_id] = ($counts[$service->customer_id] ?? 0) + 1;
        }

        if ($counts === []) {
            return [];
        }

        $customers = Customer::query()
            ->whereIn('id', array_keys($counts))
            ->with(Customer::displayNameWith())
            ->get()
            ->keyBy('id');

        $invoices = $this->invoicesFor(array_keys($counts), $invoicesPerCustomer);
        $credited = $this->creditedBy($incident);

        $rows = [];

        foreach ($counts as $customerId => $count) {
            $customer = $customers->get($customerId);

            if (! $customer instanceof Customer) {
                continue;
            }

            $rows[] = [
                'customer' => $customer,
                'services' => $count,
                'invoices' => $invoices[$customerId] ?? [],
                'credited' => $credited[$customerId] ?? null,
            ];
        }

        // Most services first: an outage's biggest loser is the conversation
        // somebody is having this morning.
        usort($rows, static fn (array $a, array $b): int => $b['services'] <=> $a['services']);

        return $rows;
    }

    /**
     * Their issued invoices, newest first.
     *
     * A draft is left out because a draft is still editable — crediting one
     * would be correcting a document nobody has seen, which is an edit with
     * extra paperwork. A cancelled one is out for the same reason.
     *
     * @param  list<string>  $customerIds
     * @return array<string, list<Invoice>>
     */
    private function invoicesFor(array $customerIds, int $perCustomer): array
    {
        $rows = Invoice::query()
            ->whereIn('customer_id', $customerIds)
            ->whereNotIn('status', [
                InvoiceStatus::Draft->value,
                InvoiceStatus::Cancelled->value,
            ])
            ->latest('issued_on')
            ->orderByDesc('id')
            ->get();

        $byCustomer = [];

        foreach ($rows as $invoice) {
            $id = (string) $invoice->customer_id;

            if (count($byCustomer[$id] ?? []) >= $perCustomer) {
                continue;
            }

            $byCustomer[$id][] = $invoice;
        }

        return $byCustomer;
    }

    /**
     * What has already been credited for this incident, per customer.
     *
     * The screen needs it to stop offering a button the use case would then
     * refuse — and the use case still refuses, because a screen rendered two
     * minutes ago is not a lock.
     *
     * @return array<string, SlaCredit>
     */
    private function creditedBy(Incident $incident): array
    {
        return SlaCredit::query()
            ->where('incident_id', $incident->id)
            ->get()
            ->keyBy('customer_id')
            ->all();
    }
}
