<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RecordSamples;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Infrastructure\ResourceGraph;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\LoadBalancerProvider;
use App\Domain\Infrastructure\LoadBalancing\Backend;
use App\Domain\Infrastructure\LoadBalancing\Listener;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricSample;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\SampleBatch;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks every load balancer what it is listening on, and writes it into the
 * graph (§9).
 *
 * The same shape as `DiscoverStorage`, and for the same reasons: nodes rather
 * than a table, connection counts and request rates as telemetry rather than
 * as attributes, and a failed read that retires nothing.
 *
 * **A backend node is linked to the machine behind it**, where the adapter
 * can say which. That edge is what makes §16's rolling maintenance possible
 * at all: "which balancers is this server behind" has to be answerable before
 * anybody can take it out of service safely, and it is a walk rather than a
 * column because a machine is often behind more than one.
 *
 * Hourly. A listener list changes when somebody deploys; a backend's *state*
 * changes faster than that, which is why the drain screen re-reads one
 * backend rather than trusting the sweep.
 */
final readonly class DiscoverLoadBalancers implements AutomationRun
{
    private const string ListenerKind = 'lb_listener';

    private const string BackendKind = 'lb_backend';

    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private ResourceGraph $graph,
        private RecordSamples $samples,
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

        if (! $adapter instanceof LoadBalancerProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::LoadBalancerRead)) {
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        try {
            $written = $this->write($organizationId, $registered, $adapter->listeners());
        } catch (Throwable $exception) {
            // A read that failed writes nothing and retires nothing: a
            // balancer that is unreachable must not look like one whose
            // backends have all been removed.
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
                '%d listeners, %d backends, %d placed',
                $written['listeners'],
                $written['backends'],
                $written['placed'],
            ),
        ));
    }

    /**
     * @param  list<Listener>  $listeners
     * @return array{listeners: int, backends: int, placed: int}
     */
    private function write(string $organizationId, RegisteredAdapter $registered, array $listeners): array
    {
        $source = substr('loadbalancer:'.$registered->descriptor->key, 0, 48);
        $at = CarbonImmutable::now();

        $seen = [];
        $backends = 0;
        $placed = 0;
        /** @var list<MetricSample> $samples */
        $samples = [];

        foreach ($listeners as $listener) {
            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::ListenerKind,
                nodeKey: $registered->descriptor->key.'/'.$listener->key,
                label: $listener->name,
                source: $source,
                attributes: array_filter([
                    'address' => $listener->address,
                    'port' => $listener->port,
                    'protocol' => $listener->protocol,
                    'vendor' => $registered->descriptor->vendor,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $seen[$node->node_key] = true;

            if ($listener->activeConnections !== null) {
                $samples[] = new MetricSample(
                    $node->node_key,
                    MetricKind::Sessions,
                    (float) $listener->activeConnections,
                    $at,
                );
            }

            if ($listener->requestsPerSecond !== null) {
                $samples[] = new MetricSample(
                    $node->node_key,
                    MetricKind::RequestRate,
                    $listener->requestsPerSecond,
                    $at,
                );
            }

            foreach ($listener->backends as $backend) {
                $backends++;

                $backendNode = $this->writeBackend(
                    $organizationId,
                    $registered->descriptor->key,
                    $node,
                    $listener,
                    $backend,
                    $source,
                );

                $seen[$backendNode->node_key] = true;

                if ($backend->activeConnections !== null) {
                    $samples[] = new MetricSample(
                        $backendNode->node_key,
                        MetricKind::Sessions,
                        (float) $backend->activeConnections,
                        $at,
                    );
                }

                if ($this->placeBehind($organizationId, $backendNode, $backend, $source)) {
                    $placed++;
                }
            }
        }

        $this->retireDeparted($organizationId, $source, array_keys($seen));

        if ($samples !== []) {
            $this->samples->handle($organizationId, $source, new SampleBatch($samples), $at);
        }

        return ['listeners' => count($listeners), 'backends' => $backends, 'placed' => $placed];
    }

    private function writeBackend(
        string $organizationId,
        string $adapterKey,
        ResourceNode $listenerNode,
        Listener $listener,
        Backend $backend,
        string $source,
    ): ResourceNode {
        $node = $this->graph->upsertNode(
            organizationId: $organizationId,
            kind: self::BackendKind,
            // Qualified by the listener, because `web-1` is behind four of
            // them and a node key is unique per organization.
            nodeKey: $adapterKey.'/'.$listener->key.'/'.$backend->key,
            label: $backend->name,
            source: $source,
            attributes: array_filter([
                'state' => $backend->state->value,
                'address' => $backend->address,
                'port' => $backend->port,
                'weight' => $backend->weight,
                // The keys the drain endpoint needs, written down rather than
                // parsed back out of the node key: a balancer whose own
                // identifiers contain a slash would make that parse wrong,
                // and a drain sent to the wrong backend is an outage.
                'listener_key' => $listener->key,
                'backend_key' => $backend->key,
                'adapter' => $adapterKey,
            ], static fn (mixed $value): bool => $value !== null),
        );

        $this->graph->attach($listenerNode, $node, Relation::Contains, source: $source);

        return $node;
    }

    /**
     * The machine this backend is, where the graph already has it.
     *
     * `Relation::Serves`, because that is what a backend does for a listener's
     * traffic — and the edge points from the machine to the backend, so both
     * ends are the provider's and the boundary rule is satisfied.
     */
    private function placeBehind(
        string $organizationId,
        ResourceNode $backendNode,
        Backend $backend,
        string $source,
    ): bool {
        if ($backend->nodeKey === null) {
            return false;
        }

        $machine = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('node_key', $backend->nodeKey)
            ->whereNull('retired_at')
            ->first();

        if (! $machine instanceof ResourceNode) {
            return false;
        }

        $this->graph->attach($machine, $backendNode, Relation::Serves, source: $source);

        return true;
    }

    /**
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
