<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RecordSamples;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\MonitoringProvider;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks every monitoring adapter what it currently knows.
 *
 * The other half of the projection: one task puts the resources in the graph and
 * this one puts numbers against them. Without it Phase A would ship a Telemetry
 * screen that could only ever be empty.
 *
 * Four things it is careful about.
 *
 * **It asks only about resources this installation has.** The node keys come from
 * the graph, so an adapter is never asked about a machine nobody here runs, and a
 * reading about something unknown is counted rather than stored (`RecordSamples`).
 * That is what stops a misconfigured poller inventing inventory.
 *
 * **It respects the batch size the adapter declared.** A poller that can answer
 * for five hundred hosts in one query says so; asking it five hundred times
 * politely is how an integration gets rate-limited out of its own account.
 *
 * **One adapter failing never stops the others**, and a failure is recorded
 * against the adapter rather than thrown. The redaction matters here more than
 * anywhere: a provider exception message is the likeliest place a credential
 * reaches a database column, and this column is read on a screen.
 *
 * **It asks the capability first.** An adapter without `MetricsRead` permitted —
 * disabled, or a module whose row an operator switched off — is skipped, not
 * called and caught.
 */
final readonly class CollectTelemetry implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private RecordSamples $samples,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->collectFrom($summary, $organizationId, $registered);
                }
            }

            return $summary;
        });
    }

    private function collectFrom(
        RunSummary $summary,
        string $organizationId,
        RegisteredAdapter $registered,
    ): RunSummary {
        $adapter = $registered->adapter();

        if (! $adapter instanceof MonitoringProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::MetricsRead)) {
            // Not a failure and not silence either: an adapter an operator turned
            // off is examined and skipped, so "why did nothing arrive" has an
            // answer on the run record.
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        $targets = $this->targetsFor($organizationId);

        if ($targets === []) {
            return $summary->skipping();
        }

        try {
            $outcome = $this->ask($registered, $organizationId, $targets);
        } catch (Throwable $exception) {
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        $registered->row->last_collected_at = CarbonImmutable::now();
        $registered->row->save();

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            ResourceAdapter::class,
            $registered->row->id,
            $registered->descriptor->name,
            sprintf('%d recorded, %d refused', $outcome['recorded'], $outcome['refused']),
        ));
    }

    /**
     * @param  list<string>  $targets
     * @return array{recorded: int, refused: int}
     */
    private function ask(RegisteredAdapter $registered, string $organizationId, array $targets): array
    {
        $adapter = $registered->adapter();

        if (! $adapter instanceof MonitoringProvider) {
            return ['recorded' => 0, 'refused' => 0];
        }

        $batchSize = $registered->descriptor->limits->batchSize;
        $batches = $batchSize > 0 ? array_chunk($targets, $batchSize) : [$targets];

        $recorded = 0;
        $refused = 0;

        foreach ($batches as $batch) {
            $outcome = $this->samples->handle(
                $organizationId,
                $registered->descriptor->key,
                $adapter->collect(array_values($batch)),
            );

            $recorded += $outcome->recorded;
            $refused += $outcome->refusedCount();
        }

        return ['recorded' => $recorded, 'refused' => $refused];
    }

    /**
     * The node keys this adapter may be asked about.
     *
     * Every live node in the organization's subtree, which for the provider is the
     * whole installation. An adapter that has never heard of most of them says so
     * through `unknownTargets`, and that is cheaper than core trying to guess which
     * resources belong to which source — a guess that would be wrong the first time
     * somebody added a second poller.
     *
     * @return list<string>
     */
    private function targetsFor(string $organizationId): array
    {
        $organization = Organization::query()
            ->withoutGlobalScope('organization')
            ->whereKey($organizationId)
            ->first();

        if (! $organization instanceof Organization) {
            return [];
        }

        $keys = ResourceNode::query()
            ->withoutGlobalScope('organization')
            ->whereIn('organization_id', Organization::query()
                ->withoutGlobalScope('organization')
                ->where('path', 'like', $organization->path.'%')
                ->select('id'))
            ->whereNull('retired_at')
            ->pluck('node_key')
            ->all();

        return array_values(array_unique(array_filter($keys, is_string(...))));
    }

    /**
     * @return list<string>
     */
    private function providerOrganizationIds(): array
    {
        $ids = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('type', OrganizationType::Provider->value)
            ->pluck('id')
            ->all();

        return array_values(array_filter($ids, is_string(...)));
    }
}
