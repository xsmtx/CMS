<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Billing\RecordUsage;
use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\UsageMeter;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks every metering source what was used, for the month that has ended
 * (§25).
 *
 * **The period is the month before this one, and it is asked for every day.**
 * Not "the month that just ended, on the first" — a run that has to happen on
 * a particular day is a run that loses a month permanently the first time a
 * worker is down for one. The snapshot's unique key makes every repeat a
 * skip, which is what turns a scheduled task into one that catches up (ADR
 * 0031).
 *
 * **A closed month, never the current one.** A figure for a month still
 * running will change, and this platform is about to write it onto an invoice
 * that cannot (ADR 0023).
 *
 * **A source that failed writes nothing.** An empty answer means "nothing was
 * used", which is a real and billable answer, so a source that is merely
 * unreachable must not be able to say it — the contract says so in as many
 * words.
 */
final readonly class CollectUsage implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private RecordUsage $usage,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        $start = CarbonImmutable::now()->subMonth()->startOfMonth();
        $end = $start->endOfMonth();

        return $this->organizations->withoutBoundary(function () use ($start, $end): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->askOne($summary, $organizationId, $registered, $start, $end);
                }
            }

            return $summary;
        });
    }

    private function askOne(
        RunSummary $summary,
        string $organizationId,
        RegisteredAdapter $registered,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): RunSummary {
        $adapter = $registered->adapter();

        if (! $adapter instanceof UsageMeter) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::UsageRead)) {
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();

        try {
            $readings = $adapter->usage($start, $end);
        } catch (Throwable $exception) {
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        $outcome = $this->usage->handle($organizationId, $registered->descriptor->key, $readings);

        if ($outcome['recorded'] === 0) {
            // Nothing new. Every repeat of a month already recorded is a
            // skip, which is what makes running this daily safe.
            return $summary->skipping();
        }

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            ResourceAdapter::class,
            $registered->row->id,
            $registered->descriptor->name,
            sprintf(
                '%s: %d recorded, %d already known, %d unmetered, %d in the wrong unit',
                $start->format('Y-m'),
                $outcome['recorded'],
                $outcome['skipped'],
                $outcome['unmetered'],
                $outcome['refused'],
            ),
        ));
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
