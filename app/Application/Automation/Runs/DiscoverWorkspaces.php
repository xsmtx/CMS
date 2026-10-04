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
use App\Domain\Infrastructure\Contracts\InfrastructureAsCodeProvider;
use App\Domain\Infrastructure\Iac\IacWorkspace;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Asks every infrastructure-as-code tool what workspaces it has (§25).
 *
 * Nodes, like every other discovery here, and for the reason §25 gives: a
 * guarded change points at a `resource_nodes` row, so a workspace has to be
 * one before anybody can ask for a change against it. Making an operator type
 * a workspace id into a form would be making them type the thing the adapter
 * already knows.
 *
 * **No telemetry and no edges.** A workspace is not inside anything this
 * platform knows about, and the resources it manages are the ones other
 * adapters discover on their own — writing an edge from a Terraform workspace
 * to a server it created would be two sources claiming the same machine, and
 * the one that ran last would win. The resource *count* is an attribute, which
 * is a fact the adapter reported rather than a number this platform measured.
 *
 * **It retires only what it wrote**, scoped by source: the rule every sweep
 * since `ProjectCoreResources` has stated.
 *
 * Hourly. A workspace list changes when somebody adds one, and the state
 * serial that matters is read fresh at the moment of an apply rather than from
 * whatever this sweep last saw — a cached serial used as the safety check
 * would be a safety check against an hour ago.
 */
final readonly class DiscoverWorkspaces implements AutomationRun
{
    private const string Kind = 'iac_workspace';

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

        if (! $adapter instanceof InfrastructureAsCodeProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::AutomationStateRead)) {
            // Examined and skipped rather than silently passed over: an
            // adapter an operator turned off is why nothing arrived.
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        try {
            $workspaces = $adapter->workspaces();
            $this->write($organizationId, $registered, $workspaces);
        } catch (Throwable $exception) {
            /*
             * A read that failed writes nothing and retires nothing. A
             * Terraform installation that is merely unreachable must not look
             * like an estate somebody deleted overnight — and the nodes it
             * owns are what the change queue points at.
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
            sprintf('%d workspaces', count($workspaces)),
        ));
    }

    /**
     * @param  list<IacWorkspace>  $workspaces
     */
    private function write(string $organizationId, RegisteredAdapter $registered, array $workspaces): void
    {
        // `resource_nodes.source` is 48 characters. An adapter key longer than
        // the remainder would fail the insert rather than the discovery.
        $source = substr('iac:'.$registered->descriptor->key, 0, 48);

        $seen = [];

        foreach ($workspaces as $workspace) {
            if ($workspace->key === '') {
                continue;
            }

            $node = $this->graph->upsertNode(
                organizationId: $organizationId,
                kind: self::Kind,
                // Qualified by the adapter, because `production` is what half
                // the estate is called and a node key is unique per
                // organization.
                nodeKey: $registered->descriptor->key.'/'.$workspace->key,
                label: $workspace->name === '' ? $workspace->key : $workspace->name,
                source: $source,
                attributes: array_filter([
                    'tool' => $registered->descriptor->vendor,
                    'resources' => $workspace->resources,
                    /*
                     * What the adapter said, not a number this platform
                     * measured — and **not** the safety check. The serial that
                     * guards an apply is read fresh moments before the run; a
                     * cached one would be a check against whenever this sweep
                     * last ran.
                     */
                    'state_serial' => $workspace->stateSerial,
                    'locked_by' => $workspace->lockedBy,
                    'last_applied_at' => $workspace->lastAppliedAt?->toIso8601String(),
                ], static fn (mixed $value): bool => $value !== null),
            );

            $seen[$node->node_key] = true;
        }

        $this->retireDeparted($organizationId, $source, array_keys($seen));
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
