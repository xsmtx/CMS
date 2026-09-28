<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Intelligence\ReconcileServices;
use App\Application\Intelligence\RecordFindings;
use App\Application\Intelligence\Remediations;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Intelligence\Models\ReconciliationFinding;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Compares what this platform believes against what its providers report
 * (§22).
 *
 * **It reports and never repairs.** ADR 0031 and ADR 0032 applied to a
 * comparison: a sweep that put right what it found would suspend a customer
 * because a panel was slow to answer, and the audit row afterwards would say
 * the platform had done it to itself. A finding becomes an action only when
 * somebody approves a proposal.
 *
 * **Hourly, not every five minutes.** Every run is one request per service to
 * somebody else's control panel; a fleet of two thousand services asked every
 * five minutes is twenty-four thousand requests an hour at a provider who did
 * not agree to that. The differences this finds are hours old by nature —
 * somebody suspended an account by hand — and an hour is the honest pace.
 *
 * One organization failing never stops the sweep, which is the rule every
 * task here lives under.
 */
final readonly class Reconcile implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private ReconcileServices $services,
        private RecordFindings $findings,
        private Remediations $remediations,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                $summary = $this->reconcile($summary, $organizationId);
            }

            return $summary;
        });
    }

    private function reconcile(RunSummary $summary, string $organizationId): RunSummary
    {
        $summary = $summary->examining();

        try {
            $differences = $this->services->handle($organizationId);
            $written = $this->findings->handle(
                $organizationId,
                ReconcileServices::Resource,
                $differences,
            );

            /*
             * A proposal per open finding, and never one an operator has
             * already decided or chosen for themselves. Writing it here
             * rather than on the screen means the queue arrives with an
             * answer beside each row — which is the difference between a
             * list of problems and a list of decisions.
             */
            $this->proposeFor($organizationId);
        } catch (Throwable $exception) {
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                Organization::class,
                $organizationId,
                null,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        // A run that found nothing is still written, which is the whole of
        // `RecordedRun`'s reason for existing: "the sweep ran and everything
        // agreed" is an answer somebody wants at nine in the morning.
        if ($written['raised'] === 0 && $written['cleared'] === 0) {
            return $summary->skipping();
        }

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            Organization::class,
            $organizationId,
            null,
            sprintf(
                '%d raised, %d still true, %d put right, %d dismissed',
                $written['raised'],
                $written['kept'],
                $written['cleared'],
                $written['dismissed'],
            ),
        ));
    }

    /**
     * Put the platform's own suggestion beside every open finding.
     *
     * One proposal failing never stops the sweep, which is the rule every
     * task here lives under — and a suggestion is the least important thing
     * in this run to get right.
     */
    private function proposeFor(string $organizationId): void
    {
        foreach (
            ReconciliationFinding::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->open()
                ->cursor() as $finding
        ) {
            try {
                $this->remediations->propose($finding);
            } catch (Throwable) {
                continue;
            }
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
