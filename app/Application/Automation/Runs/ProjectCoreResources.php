<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\ResourceKind;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Puts what this platform already knows into the graph.
 *
 * Without this, Phase A would be four tables nobody can see. With it, an
 * operator who installs no modules at all opens Explorer and finds their
 * organizations, their servers, the services on each and the revenue underneath —
 * built from rows that have been there since Phase 6.
 *
 * **A task rather than a set of event listeners**, and the choice matters. There
 * is no `ServerCreated` event and adding six of them so that inventory works
 * would be six new obligations on unrelated code; worse, a missed event is a node
 * that never appears with nothing to notice it. A run asks a question about rows —
 * which servers have no node, which nodes have no server — so a scheduler that was
 * down for three days catches up rather than skipping three days of inventory
 * permanently (ADR 0031).
 *
 * **It runs across the whole installation** and therefore outside the boundary,
 * with every organization named explicitly. `ResourceGraph` refuses an edge whose
 * ends are in different subtrees, which is the guard that makes that safe.
 *
 * What it projects is deliberately small: organizations, servers, services, and
 * the two relations between them. Racks, VMs, ports and prefixes belong to
 * modules, and a projection that invented them would be core modelling hardware
 * it has never seen.
 */
final readonly class ProjectCoreResources implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private ResourceGraph $graph,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            // One escape for the whole run, and every query happens inside it.
            // A builder handed back out of the callback would be scoped again by
            // the time anything ran it, and the symptom is an empty result with
            // no error — which is why the cursors below are consumed here.
            $summary = $this->projectOrganizations(new RunSummary);
            $summary = $this->projectServers($summary);
            $summary = $this->projectServices($summary);

            return $this->retireDeparted($summary);
        });
    }

    private function projectOrganizations(RunSummary $summary): RunSummary
    {
        foreach (Organization::query()->orderBy('path')->cursor() as $organization) {
            $summary = $summary->examining();

            try {
                $node = $this->graph->upsertNode(
                    organizationId: $organization->id,
                    kind: ResourceKind::Organization,
                    nodeKey: $organization->id,
                    label: $this->labelFor($organization),
                    subject: $organization,
                );

                $summary = $node->wasRecentlyCreated
                    ? $summary->changing($this->item($organization->id, $organization->name, 'organization'))
                    : $summary->skipping();
            } catch (Throwable $exception) {
                $summary = $summary->failing($this->failure($organization->id, $organization->name, $exception));
            }
        }

        return $summary;
    }

    private function projectServers(RunSummary $summary): RunSummary
    {
        foreach (Server::query()->oldest()->cursor() as $server) {
            $summary = $summary->examining();

            try {
                $node = $this->graph->upsertNode(
                    organizationId: $server->organization_id,
                    kind: ResourceKind::Server,
                    // The ULID, not the hostname. A hostname is what an adapter
                    // knows and it is also not unique: two servers sharing one
                    // would silently become a single node, because the unique key
                    // is what makes a second run idempotent. What an adapter's own
                    // inventory calls the machine is matched against the hostname
                    // written here, in `RecordSamples::byHostname()` - and two
                    // nodes claiming one hostname match nothing rather than one
                    // of them.
                    nodeKey: $server->id,
                    label: $server->name,
                    subject: $server,
                    attributes: ['hostname' => $server->hostname, 'region' => $server->region],
                );

                $changed = $node->wasRecentlyCreated;

                $owner = $this->organizationNode($server->organization_id);

                if ($owner instanceof ResourceNode) {
                    $edge = $this->graph->attach($owner, $node, Relation::Contains);
                    $changed = $changed || $edge->wasRecentlyCreated;
                }

                $summary = $changed
                    ? $summary->changing($this->item($server->id, $server->name, 'server'))
                    : $summary->skipping();
            } catch (Throwable $exception) {
                $summary = $summary->failing($this->failure($server->id, $server->name, $exception));
            }
        }

        return $summary;
    }

    private function projectServices(RunSummary $summary): RunSummary
    {
        $query = Service::query()
            ->where('status', '!=', ServiceStatus::Terminated->value)->oldest();

        foreach ($query->cursor() as $service) {
            $summary = $summary->examining();

            try {
                $node = $this->graph->upsertNode(
                    organizationId: $service->organization_id,
                    kind: ResourceKind::Service,
                    nodeKey: $service->id,
                    label: $service->name,
                    subject: $service,
                    attributes: ['domain' => $service->domain, 'status' => $service->status->value],
                );

                $changed = $node->wasRecentlyCreated;

                if ($service->server_id !== null) {
                    $server = $this->serverNode($service->server_id);

                    if ($server instanceof ResourceNode) {
                        // Exclusive: a service is hosted by one server, so a
                        // migration closes the old edge instead of adding a
                        // second. That is what makes the history readable —
                        // "moved from node-3 to node-7 on the 14th" — rather than
                        // ambiguous.
                        $edge = $this->graph->attach(
                            $server,
                            $node,
                            Relation::Hosts,
                            exclusive: true,
                        );

                        $changed = $changed || $edge->wasRecentlyCreated;
                    }
                }

                $summary = $changed
                    ? $summary->changing($this->item($service->id, $service->name, 'service'))
                    : $summary->skipping();
            } catch (Throwable $exception) {
                $summary = $summary->failing($this->failure($service->id, $service->name, $exception));
            }
        }

        return $summary;
    }

    /**
     * Nodes whose subject has gone, or has stopped being a live thing.
     *
     * Retired rather than deleted: a terminated service is exactly what an
     * incident review needs to see, and deleting the node would shorten every
     * historical path that ran through it. Its edges close, so it stops counting
     * towards impact — which is the behaviour that actually matters.
     *
     * Only nodes this projection owns are touched. A node a module discovered is
     * the module's business, and a sweep that retired what it had never seen
     * would silently empty somebody else's screen.
     */
    private function retireDeparted(RunSummary $summary): RunSummary
    {
        $query = ResourceNode::query()
            ->where('source', 'core')
            ->whereIn('kind', ResourceKind::Core)
            ->whereNull('retired_at');

        foreach ($query->cursor() as $node) {
            $summary = $summary->examining();

            if ($this->stillExists($node)) {
                $summary = $summary->skipping();

                continue;
            }

            try {
                $this->graph->retire($node);

                $summary = $summary->changing(new RunItem(
                    ItemOutcome::Changed,
                    ResourceNode::class,
                    $node->id,
                    $node->label,
                    'retired',
                ));
            } catch (Throwable $exception) {
                $summary = $summary->failing($this->failure($node->id, $node->label, $exception));
            }
        }

        return $summary;
    }

    private function stillExists(ResourceNode $node): bool
    {
        return match ($node->kind) {
            ResourceKind::Organization => Organization::query()->whereKey($node->subject_id)->exists(),
            ResourceKind::Server => Server::query()->whereKey($node->subject_id)->exists(),
            ResourceKind::Service => Service::query()
                ->whereKey($node->subject_id)
                ->where('status', '!=', ServiceStatus::Terminated->value)
                ->exists(),
            // A kind core does not project is not core's to retire.
            default => true,
        };
    }

    private function organizationNode(string $organizationId): ?ResourceNode
    {
        return ResourceNode::query()
            ->where('organization_id', $organizationId)
            ->where('kind', ResourceKind::Organization)
            ->where('node_key', $organizationId)
            ->first();
    }

    private function serverNode(string $serverId): ?ResourceNode
    {
        return ResourceNode::query()
            ->where('kind', ResourceKind::Server)
            ->where('node_key', $serverId)
            ->first();
    }

    /**
     * What to call an organization in the graph.
     *
     * A node carries a **cached label** (ADR 0043), and for a customer the
     * organization's own name is the wrong thing to cache: `CreateCustomer` has
     * no personal name to use when an individual signs up with no company, so
     * the row is literally called "Customer". Four of those in an impact view
     * tell an operator nothing at all.
     *
     * `Customer::displayName()` is the name a human uses for this customer and
     * falls through company, legal name, then the primary contact — so it is
     * eager-loaded with `displayNameWith()`, never `with('customer')` alone,
     * because a column the caller did not anticipate is a lazy-load exception by
     * another route.
     */
    private function labelFor(Organization $organization): string
    {
        if ($organization->type !== OrganizationType::Customer) {
            return $organization->name;
        }

        $customer = Customer::query()
            ->with(Customer::displayNameWith())
            ->where('organization_id', $organization->id)
            ->first();

        return $customer instanceof Customer ? $customer->displayName() : $organization->name;
    }

    private function item(string $id, string $label, string $kind): RunItem
    {
        return new RunItem(ItemOutcome::Changed, ResourceNode::class, $id, $label, $kind);
    }

    private function failure(string $id, string $label, Throwable $exception): RunItem
    {
        return new RunItem(
            ItemOutcome::Failed,
            ResourceNode::class,
            $id,
            $label,
            $exception->getMessage(),
        );
    }
}
