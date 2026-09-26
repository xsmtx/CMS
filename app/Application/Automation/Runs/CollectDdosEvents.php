<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Application\Network\RecordDdosEvent;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\Contracts\DdosProvider;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Network\Models\DdosEvent;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks every DDoS source what it has seen, and works out whose it was.
 *
 * **The window comes from the rows, never from the clock** (ADR 0031). It is
 * the start of the most recent event this source already reported, less a
 * short overlap — not "the last five minutes", which would lose an afternoon
 * permanently the first time a worker was down for one. The overlap is
 * deliberate: an attack that was still running when it was last seen has
 * moved on since, and asking again is how its end and its true peak arrive.
 * Re-reporting is free because the record is keyed on the provider's own
 * reference.
 *
 * **A source with nothing recorded yet is asked about a day**, which is long
 * enough to arrive with history on a fresh installation and short enough that
 * a vendor holding a year of events does not send all of it.
 *
 * One source failing never stops the others, and the failure is recorded
 * against the adapter row through the redactor — a provider's exception
 * message is a likely place for a credential to reach a column that is then
 * read on a screen.
 */
final readonly class CollectDdosEvents implements AutomationRun
{
    /**
     * How far back to ask beyond what is already known.
     *
     * Ten minutes: longer than the sweep's own interval, so nothing falls
     * between two runs, and short enough that a source is not asked to
     * re-send an hour every five minutes.
     */
    private const int OverlapMinutes = 10;

    /** What to ask a source that has never reported anything here. */
    private const int FirstWindowHours = 24;

    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private RecordDdosEvent $events,
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

        if (! $adapter instanceof DdosProvider) {
            return $summary;
        }

        if (! $registered->permitted()->has(Capability::DdosEventRead)) {
            // Examined and skipped rather than passed over in silence: an
            // adapter an operator turned off is why nothing arrived, and the
            // run record is where that question gets asked.
            return $summary->examining()->skipping();
        }

        $summary = $summary->examining();
        $source = $registered->descriptor->key;

        try {
            $attacks = $adapter->attacks($this->since($organizationId, $source));
        } catch (Throwable $exception) {
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                ResourceAdapter::class,
                $registered->row->id,
                $registered->descriptor->name,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        if ($attacks === []) {
            return $summary->skipping();
        }

        $attributed = 0;

        foreach ($attacks as $attack) {
            $event = $this->events->handle($organizationId, $source, $attack);

            if ($event->customer_id !== null) {
                $attributed++;
            }
        }

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            ResourceAdapter::class,
            $registered->row->id,
            $registered->descriptor->name,
            sprintf('%d events, %d attributed', count($attacks), $attributed),
        ));
    }

    /**
     * How far back to ask this source.
     */
    private function since(string $organizationId, string $source): CarbonImmutable
    {
        $latest = DdosEvent::query()
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->max('started_at');

        return $latest === null
            ? CarbonImmutable::now()->subHours(self::FirstWindowHours)
            : CarbonImmutable::parse((string) $latest)->subMinutes(self::OverlapMinutes);
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
