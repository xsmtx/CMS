<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Application\Reports\MoneyByCurrency;
use App\Domain\Infrastructure\ResourceKind;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Resources\Models\ResourceNode;

/**
 * The number that turns an alert into a priority.
 *
 * Every monitoring system can say a disk is full. None of them can say that the
 * disk carries forty services belonging to nine customers and 4,200 EUR a month,
 * because none of them has the billing database. This is the one thing in
 * handoff #2 that only this platform can do, which is why it is in the first
 * phase rather than with the incidents that will render it.
 *
 * Two deliberate narrowings:
 *
 * **Only relations that propagate impact are followed.** An IP assignment does
 * not fail when the server does — the address is a fact about allocation — and
 * following it would count the same customer twice through two paths.
 *
 * **Revenue comes from the services, not from the graph.** The nodes say which
 * services are below; `services.recurring_minor` says what they are worth. The
 * graph stores no money at all, which is what keeps it from being a second,
 * disagreeing source of truth about an amount (ADR 0043).
 */
final readonly class ImpactSummary
{
    public function __construct(private ResourceTree $tree) {}

    public function for(ResourceNode $node, int $maxDepth = ResourceGraph::MaxDepth): ImpactFigures
    {
        $rows = $this->tree->below($node, $maxDepth, impactOnly: true);

        $byKind = [];
        $serviceIds = [];
        $deepest = 0;

        foreach ($rows as $row) {
            if ($row->depth > 0) {
                $byKind[$row->node->kind] = ($byKind[$row->node->kind] ?? 0) + 1;
            }

            $deepest = max($deepest, $row->depth);

            if ($row->node->kind === ResourceKind::Service && $row->node->subject_id !== null) {
                $serviceIds[] = $row->node->subject_id;
            }
        }

        [$services, $customers, $recurring] = $this->revenueOf($serviceIds);

        return new ImpactFigures(
            services: $services,
            customers: $customers,
            recurring: $recurring,
            nodesByKind: $byKind,
            depth: $deepest,
            truncated: $deepest >= max(1, $maxDepth),
        );
    }

    /**
     * Services, distinct customers, and what they pay.
     *
     * The service count comes from the rows that were readable rather than from
     * the nodes that were reachable, and the difference matters: a node whose
     * service row the boundary hides would otherwise be counted here and
     * contribute nothing to the money, so a screen would say "12 services, 0
     * EUR" and be wrong twice.
     *
     * @param  list<string>  $serviceIds
     * @return array{0: int, 1: int, 2: MoneyByCurrency}
     */
    private function revenueOf(array $serviceIds): array
    {
        $recurring = new MoneyByCurrency;

        if ($serviceIds === []) {
            return [0, 0, $recurring];
        }

        // Only the four columns this needs. A partial select is safe here
        // precisely because nothing renders a customer name from these rows —
        // the rule that bit orders and invoices twice applies to *display*, and
        // this is arithmetic.
        $services = Service::query()
            ->whereIn('id', $serviceIds)
            ->get(['id', 'customer_id', 'currency_code', 'recurring_minor']);

        $customers = [];

        foreach ($services as $service) {
            $customers[$service->customer_id] = true;
            $recurring->add($service->currency_code, $service->recurring_minor);
        }

        return [$services->count(), count($customers), $recurring];
    }
}
