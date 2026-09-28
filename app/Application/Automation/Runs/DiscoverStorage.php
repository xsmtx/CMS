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
use App\Domain\Infrastructure\Contracts\StorageProvider;
use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricSample;
use App\Domain\Infrastructure\Relation;
use App\Domain\Infrastructure\SampleBatch;
use App\Domain\Infrastructure\Storage\StoragePool;
use App\Domain\Infrastructure\Storage\StorageVolume;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks every storage system what it is serving, and writes it into the graph
 * (§9).
 *
 * **Nodes, not a table**, which is what `ResourceKind`'s own docblock names as
 * the example: core owns four kinds because it owns four kinds of row, and a
 * storage volume is a thing a module knows about. The graph then answers §9's
 * "attached workloads" with the walk it already does — pool contains volume,
 * server hosts volume, server contains service, service belongs to a customer
 * — and `ImpactSummary` needs no new code at all.
 *
 * **Capacity is telemetry, not an attribute.** It goes through `RecordSamples`
 * as `disk.total` and `disk.used`, which is what `CapacityForecast` reads and
 * what the Telemetry screen already draws. Writing it onto the node would be a
 * second copy of a number that changes every hour, and the daily rollup would
 * never see it.
 *
 * **An edge to a workload is only written where the node exists.** A volume
 * attached to a machine this installation has never heard of keeps the name as
 * an attribute, which is a true and useful thing to see; inventing a node for
 * it would put hardware into the graph on the word of one adapter.
 *
 * **It retires only what it wrote**, scoped by source — the rule every sweep
 * since `ProjectCoreResources` has stated, because retiring by kind takes a
 * second adapter's estate with it every time this one runs.
 *
 * Hourly. A pool's capacity moves slowly and a volume list moves when somebody
 * provisions; asking an array's API every five minutes is a rate limit rather
 * than fresher data, and the monitoring adapters are what watch a pool by the
 * minute.
 */
final readonly class DiscoverStorage implements AutomationRun
{
    private const string PoolKind = 'storage_pool';

    private const string VolumeKind = 'storage_volume';

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

        if (! $adapter instanceof StorageProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::StorageCapacityRead)) {
            // Examined and skipped rather than silently passed over: an
            // adapter an operator turned off is why nothing arrived.
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        try {
            $pools = $adapter->pools();
            $volumes = $adapter->volumes();
            $written = $this->write($organizationId, $registered, $pools, $volumes);
        } catch (Throwable $exception) {
            // A read that failed writes nothing and retires nothing: an array
            // that is merely unreachable must not look like one that was
            // decommissioned overnight.
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
            sprintf('%d pools, %d volumes, %d attached', $written['pools'], $written['volumes'], $written['attached']),
        ));
    }

    /**
     * @param  list<StoragePool>  $pools
     * @param  list<StorageVolume>  $volumes
     * @return array{pools: int, volumes: int, attached: int}
     */
    private function write(
        string $organizationId,
        RegisteredAdapter $registered,
        array $pools,
        array $volumes,
    ): array {
        // `resource_nodes.source` is 48 characters. An adapter key longer than
        // the remainder would fail the insert rather than the discovery.
        $source = substr('storage:'.$registered->descriptor->key, 0, 48);
        $at = CarbonImmutable::now();

        $seen = [];
        $poolNodes = [];
        /** @var list<MetricSample> $samples */
        $samples = [];

        foreach ($pools as $pool) {
            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::PoolKind,
                nodeKey: $registered->descriptor->key.'/'.$pool->key,
                label: $pool->name,
                source: $source,
                attributes: array_filter([
                    'health' => $pool->health->value,
                    'technology' => $pool->technology,
                    'replicas' => $pool->replicas,
                    'vendor' => $registered->descriptor->vendor,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $poolNodes[$pool->key] = $node;
            $seen[$node->node_key] = true;

            $samples = [...$samples, ...$this->capacity($node->node_key, $pool->totalBytes, $pool->usedBytes, $at)];

            if ($pool->iops !== null) {
                $samples[] = new MetricSample($node->node_key, MetricKind::DiskIops, $pool->iops, $at);
            }

            if ($pool->latencyMs !== null) {
                $samples[] = new MetricSample($node->node_key, MetricKind::DiskLatency, $pool->latencyMs, $at);
            }
        }

        $attached = 0;

        foreach ($volumes as $volume) {
            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::VolumeKind,
                // Qualified by the adapter, because `vm-101-disk-0` is what
                // half the estate is called and a node key is unique per
                // organization.
                nodeKey: $registered->descriptor->key.'/'.$volume->key,
                label: $volume->name,
                source: $source,
                attributes: array_filter([
                    'health' => $volume->health->value,
                    // Kept even when it names nothing here: a volume attached
                    // to a machine this installation has never heard of is a
                    // true and useful thing to see.
                    'attached_to' => $volume->attachedToNodeKey,
                ], static fn (mixed $value): bool => $value !== null),
            );

            $seen[$node->node_key] = true;

            $samples = [...$samples, ...$this->capacity($node->node_key, $volume->sizeBytes, $volume->usedBytes, $at)];

            $pool = $volume->poolKey === null ? null : ($poolNodes[$volume->poolKey] ?? null);

            if ($pool instanceof ResourceNode) {
                $this->graph->attach($pool, $node, Relation::Contains, source: $source);
            }

            if ($this->attachToWorkload($organizationId, $node, $volume, $source)) {
                $attached++;
            }
        }

        $this->retireDeparted($organizationId, $source, array_keys($seen));

        if ($samples !== []) {
            $this->samples->handle($organizationId, $source, new SampleBatch($samples), $at);
        }

        return ['pools' => count($pools), 'volumes' => count($volumes), 'attached' => $attached];
    }

    /**
     * The machine this volume is mounted on, where the graph already has one.
     *
     * `Relation::Hosts` and not `Contains`: a server does not contain a
     * volume that lives on an array somewhere else, it is the thing using it.
     * Both ends are the provider's, so the boundary rule the graph enforces is
     * satisfied — a customer's service is reached by walking on from the
     * server, never by an edge from the volume.
     */
    private function attachToWorkload(
        string $organizationId,
        ResourceNode $volume,
        StorageVolume $descriptor,
        string $source,
    ): bool {
        if ($descriptor->attachedToNodeKey === null) {
            return false;
        }

        $workload = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('node_key', $descriptor->attachedToNodeKey)
            ->whereNull('retired_at')
            ->first();

        if (! $workload instanceof ResourceNode) {
            return false;
        }

        $this->graph->attach($workload, $volume, Relation::Hosts, source: $source);

        return true;
    }

    /**
     * Capacity as telemetry.
     *
     * Nothing is written for a figure the source did not give: a pool with no
     * total is an S3 bucket, and a zero there would draw it as full.
     *
     * @return list<MetricSample>
     */
    private function capacity(string $target, ?int $total, ?int $used, CarbonImmutable $at): array
    {
        $samples = [];

        if ($total !== null) {
            $samples[] = new MetricSample($target, MetricKind::DiskTotal, (float) $total, $at);
        }

        if ($used !== null) {
            $samples[] = new MetricSample($target, MetricKind::DiskUsed, (float) $used, $at);
        }

        return $samples;
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
