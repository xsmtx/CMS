<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\KubernetesProvider;
use App\Domain\Infrastructure\Kubernetes\KubeNode;
use App\Domain\Infrastructure\Relation;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Asks every cluster what it is running, and writes it into the graph (§25).
 *
 * **Hosting-service context, not a dashboard.** The graph already answers the
 * question §25 names — who is affected if this node drains — through the walk
 * `ImpactSummary` does, so there are no screens of this family's own: the
 * Explorer and the impact figures draw whatever is in the graph.
 *
 * Cluster **contains** node, node **hosts** workload. And where a cluster node
 * and a `servers` row are plainly the same machine, the server **hosts** the
 * cluster node — which is what joins the two halves, because the services a
 * customer bought hang off the server and a walk from the workload then
 * reaches them.
 *
 * **That match is by hostname and ambiguity is refused**, which is
 * `RecordSamples::byHostname()`'s rule for the reason it gives: a workload
 * attached to the wrong machine is worse than one nobody placed, because the
 * first one gets acted on.
 *
 * **No edge from a workload to a customer's service, ever.** The ends would be
 * in different subtrees and `ResourceGraph::attach()` refuses it (ADR 0043) —
 * which is right, because an edge pointing that way would let a customer walk
 * up from their own service to the cluster. IPAM and storage hit the same wall
 * and took the same answer.
 *
 * **It retires only what it wrote**, scoped by source: the rule every sweep
 * since `ProjectCoreResources` has stated.
 *
 * Hourly. A workload list moves when somebody deploys, and a node that stopped
 * answering is found by whatever is monitoring it rather than by this.
 */
final readonly class DiscoverKubernetes implements AutomationRun
{
    private const string ClusterKind = 'k8s_cluster';

    private const string NodeKind = 'k8s_node';

    private const string WorkloadKind = 'k8s_workload';

    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private ResourceGraph $graph,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->discoverFrom($summary, $organizationId, $registered);
                }
            }

            return $summary;
        });
    }

    private function discoverFrom(
        RunSummary $summary,
        string $organizationId,
        RegisteredAdapter $registered,
    ): RunSummary {
        $adapter = $registered->adapter();

        if (! $adapter instanceof KubernetesProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::KubernetesRead)) {
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        try {
            $written = $this->write($organizationId, $registered, $adapter);
        } catch (Throwable $exception) {
            /*
             * A read that failed writes nothing and retires nothing. A cluster
             * that is merely unreachable must not look like one somebody tore
             * down overnight — which is what retiring on a failed read would
             * say, in the one table an impact answer is built from.
             */
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            ResourceAdapter::class,
            $registered->row->id,
            $registered->descriptor->name,
            sprintf(
                '%d clusters, %d nodes, %d workloads, %d placed',
                $written['clusters'],
                $written['nodes'],
                $written['workloads'],
                $written['placed'],
            ),
        ));
    }

    /**
     * @return array{clusters: int, nodes: int, workloads: int, placed: int}
     */
    private function write(
        string $organizationId,
        RegisteredAdapter $registered,
        KubernetesProvider $adapter,
    ): array {
        // `resource_nodes.source` is 48 characters. An adapter key longer than
        // the remainder would fail the insert rather than the discovery.
        $source = substr('k8s:'.$registered->descriptor->key, 0, 48);

        $seen = [];
        $counts = ['clusters' => 0, 'nodes' => 0, 'workloads' => 0, 'placed' => 0];

        foreach ($adapter->clusters() as $cluster) {
            if ($cluster->key === '') {
                continue;
            }

            $counts['clusters']++;

            $clusterNode = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::ClusterKind,
                nodeKey: $registered->descriptor->key.'/'.$cluster->key,
                label: $cluster->name === '' ? $cluster->key : $cluster->name,
                source: $source,
                attributes: array_filter([
                    'health' => $cluster->health->value,
                    'version' => $cluster->version,
                    'distribution' => $cluster->distribution,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $seen[$clusterNode->node_key] = true;

            $nodes = [];

            foreach ($adapter->nodes($cluster->key) as $machine) {
                if ($machine->key === '') {
                    continue;
                }

                $counts['nodes']++;

                $node = $this->graph->upsertNode(
                    organizationId: $organizationId,
                    kind: self::NodeKind,
                    nodeKey: $registered->descriptor->key.'/'.$cluster->key.'/'.$machine->key,
                    label: $machine->name === '' ? $machine->key : $machine->name,
                    source: $source,
                    attributes: array_filter([
                        'health' => $machine->health->value,
                        /*
                         * Separate from health, deliberately: a node somebody
                         * cordoned and a node that stopped answering are
                         * different things to act on, and one column would
                         * make a planned drain look like an outage.
                         */
                        'schedulable' => $machine->schedulable,
                        'hostname' => $machine->hostname,
                        'kubelet' => $machine->kubeletVersion,
                        'pods' => $machine->pods,
                    ], static fn (mixed $value): bool => $value !== null),
                );

                $nodes[$machine->key] = $node;
                $seen[$node->node_key] = true;

                $this->graph->attach($clusterNode, $node, Relation::Contains, source: $source);

                if ($this->placeOnServer($organizationId, $node, $machine, $source)) {
                    $counts['placed']++;
                }
            }

            foreach ($adapter->workloads($cluster->key) as $workload) {
                if ($workload->key === '') {
                    continue;
                }

                $counts['workloads']++;

                $node = $this->graph->upsertNode(
                    organizationId: $organizationId,
                    kind: self::WorkloadKind,
                    nodeKey: $registered->descriptor->key.'/'.$cluster->key.'/'.$workload->key,
                    label: $workload->namespace.'/'.$workload->name,
                    source: $source,
                    attributes: array_filter([
                        'health' => $workload->health->value,
                        'namespace' => $workload->namespace,
                        // The tool's own word, not an enum of ours: core
                        // cannot know every workload type a cluster grows.
                        'workload_kind' => $workload->kind,
                        'ready' => $workload->ready,
                        'desired' => $workload->desired,
                    ], static fn (mixed $value): bool => $value !== null),
                );

                $seen[$node->node_key] = true;

                foreach ($workload->nodeKeys as $key) {
                    $machine = $nodes[$key] ?? null;

                    if ($machine instanceof ResourceNode) {
                        // `Hosts` rather than `Contains`: a machine is not the
                        // thing a workload is inside, it is the thing running
                        // it — and a pod moves to another node tonight.
                        $this->graph->attach($machine, $node, Relation::Hosts, source: $source);
                    }
                }
            }
        }

        $this->retireDeparted($organizationId, $source, array_keys($seen));

        return $counts;
    }

    /**
     * The `servers` row this cluster node plainly is, where there is one.
     *
     * This is what joins the two halves: the services a customer bought hang
     * off the server, so a walk from a workload reaches them once this edge
     * exists — which is how the graph answers "who is affected if this node
     * drains" without a workload ever pointing at a customer's service.
     *
     * **Ambiguity is refused.** Two servers claiming one hostname match
     * nothing at all, because a workload attached to the wrong machine is
     * worse than one nobody placed: the first one gets acted on.
     */
    private function placeOnServer(
        string $organizationId,
        ResourceNode $node,
        KubeNode $machine,
        string $source,
    ): bool {
        $hostname = mb_strtolower(trim((string) $machine->hostname));

        if ($hostname === '') {
            return false;
        }

        $servers = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('kind', 'server')
            ->whereNull('retired_at')
            ->whereRaw('lower(label) = ?', [$hostname])
            ->limit(2)
            ->get();

        $server = $servers->first();

        if ($servers->count() !== 1 || ! $server instanceof ResourceNode) {
            return false;
        }

        $this->graph->attach($server, $node, Relation::Hosts, source: $source);

        return true;
    }

    /**
     * Close what this source has stopped naming.
     *
     * @param  list<string>  $seen
     */
    private function retireDeparted(string $organizationId, string $source, array $seen): void
    {
        $departed = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->whereNull('retired_at')
            ->whereNotIn('node_key', $seen)
            ->get();

        foreach ($departed as $node) {
            $this->graph->retire($node);
        }
    }

    /**
     * @return list<string>
     */
    private function providerOrganizationIds(): array
    {
        return array_values(Organization::query()
            ->withoutGlobalScope('organization')
            ->whereIn('type', [
                OrganizationType::Provider->value,
                OrganizationType::Reseller->value,
            ])
            ->pluck('id')
            ->all());
    }
}
