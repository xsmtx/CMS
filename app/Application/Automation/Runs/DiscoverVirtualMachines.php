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
use App\Domain\Infrastructure\Contracts\HypervisorProvider;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricSample;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\SampleBatch;
use App\Domain\Infrastructure\Virtualisation\HypervisorHost;
use App\Domain\Infrastructure\Virtualisation\VirtualMachine;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks every hypervisor what it is running, and writes it into the graph
 * (§10).
 *
 * **This is the edge the graph was always missing.** Everything discovered so
 * far hangs off something core already knew about; a host with twenty
 * machines on it is the first thing that answers "what goes down if I reboot
 * hv-3" before somebody finds out — and answering that is the whole reason
 * ADR 0043 chose a graph over a set of tables.
 *
 * **A machine whose host the sweep did not see is written anyway, with no
 * edge.** A hypervisor that answers about machines and not about hosts is a
 * real configuration (a standalone box), and refusing the machines would
 * throw away what it could tell us.
 *
 * The usual three rules: capacity is telemetry rather than attributes, the
 * sweep retires only what it wrote, and a read that failed retires nothing.
 *
 * Hourly. A machine's *power state* changes faster, which is why the power
 * screen reads one machine back rather than trusting this.
 */
final readonly class DiscoverVirtualMachines implements AutomationRun
{
    private const string HostKind = 'hypervisor_host';

    private const string MachineKind = 'virtual_machine';

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

        if (! $adapter instanceof HypervisorProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::VirtualMachineRead)) {
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        try {
            $written = $this->write($organizationId, $registered, $adapter->hosts(), $adapter->machines());
        } catch (Throwable $exception) {
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
            sprintf('%d hosts, %d machines, %d placed', $written['hosts'], $written['machines'], $written['placed']),
        ));
    }

    /**
     * @param  list<HypervisorHost>  $hosts
     * @param  list<VirtualMachine>  $machines
     * @return array{hosts: int, machines: int, placed: int}
     */
    private function write(
        string $organizationId,
        RegisteredAdapter $registered,
        array $hosts,
        array $machines,
    ): array {
        $source = substr('hypervisor:'.$registered->descriptor->key, 0, 48);
        $at = CarbonImmutable::now();
        $adapterKey = $registered->descriptor->key;

        $seen = [];
        $hostNodes = [];
        /** @var list<MetricSample> $samples */
        $samples = [];

        foreach ($hosts as $host) {
            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::HostKind,
                nodeKey: $adapterKey.'/'.$host->key,
                label: $host->name,
                source: $source,
                attributes: array_filter([
                    // Nullable, and a null is "the cluster did not say" rather
                    // than "offline": a host nobody asked about has not failed.
                    'online' => $host->online,
                    'cluster' => $host->clusterKey,
                    'vendor' => $registered->descriptor->vendor,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $hostNodes[$host->key] = $node;
            $seen[$node->node_key] = true;

            $samples = [
                ...$samples,
                ...$this->hostSamples($node->node_key, $host, $at),
            ];
        }

        $placed = 0;

        foreach ($machines as $machine) {
            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::MachineKind,
                nodeKey: $adapterKey.'/'.$machine->key,
                label: $machine->name,
                source: $source,
                attributes: array_filter([
                    'state' => $machine->state->value,
                    'kind' => $machine->kind,
                    'vcpus' => $machine->vcpus,
                    // The keys a power action needs, written down rather than
                    // parsed back out of the node key.
                    'machine_key' => $machine->key,
                    'adapter' => $adapterKey,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $seen[$node->node_key] = true;

            if ($machine->memoryBytes !== null) {
                $samples[] = new MetricSample(
                    $node->node_key,
                    MetricKind::MemoryTotal,
                    (float) $machine->memoryBytes,
                    $at,
                );
            }

            if ($machine->diskBytes !== null) {
                $samples[] = new MetricSample(
                    $node->node_key,
                    MetricKind::DiskTotal,
                    (float) $machine->diskBytes,
                    $at,
                );
            }

            $host = $machine->hostKey === null ? null : ($hostNodes[$machine->hostKey] ?? null);

            if ($host instanceof ResourceNode) {
                // Hosts, which is exactly what a hypervisor does. Containment
                // points downward, so the impact walk reaches from the host to
                // every machine on it.
                $this->graph->attach($host, $node, Relation::Hosts, source: $source);
                $placed++;
            }
        }

        $this->retireDeparted($organizationId, $source, array_keys($seen));

        if ($samples !== []) {
            $this->samples->handle($organizationId, $source, new SampleBatch($samples), $at);
        }

        return ['hosts' => count($hosts), 'machines' => count($machines), 'placed' => $placed];
    }

    /**
     * @return list<MetricSample>
     */
    private function hostSamples(string $target, HypervisorHost $host, CarbonImmutable $at): array
    {
        $samples = [];

        if ($host->memoryBytes !== null) {
            $samples[] = new MetricSample($target, MetricKind::MemoryTotal, (float) $host->memoryBytes, $at);
        }

        if ($host->memoryUsedBytes !== null) {
            $samples[] = new MetricSample($target, MetricKind::MemoryUsed, (float) $host->memoryUsedBytes, $at);
        }

        if ($host->cpuUtilisation !== null) {
            $samples[] = new MetricSample($target, MetricKind::CpuUtilisation, $host->cpuUtilisation, $at);
        }

        if ($host->uptimeSeconds !== null) {
            $samples[] = new MetricSample($target, MetricKind::Uptime, (float) $host->uptimeSeconds, $at);
        }

        return $samples;
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
