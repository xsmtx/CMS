<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Intelligence\DetectLeakage;
use App\Application\Intelligence\RecordLeaks;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Four ways money quietly stops arriving, asked once a day (§21).
 *
 * **Daily, and after the renewal sweep.** Every one of these questions is
 * "has billing got to this yet", and asking it while the nightly renewal run
 * is still working would report every service it has not reached yet — a
 * list of four hundred findings that clears itself by breakfast is a list
 * nobody opens twice.
 *
 * It reports and never invoices. Raising an invoice because a sweep noticed
 * one was missing would be this platform charging a customer nobody decided
 * to charge — and the whole point of the list is that some of these are
 * deliberate.
 *
 * One organization failing never stops the sweep.
 */
final readonly class CheckLeakage implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private DetectLeakage $leakage,
        private RecordLeaks $leaks,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->sellerOrganizationIds() as $organizationId) {
                $summary = $this->check($summary, $organizationId);
            }

            return $summary;
        });
    }

    private function check(RunSummary $summary, string $organizationId): RunSummary
    {
        $summary = $summary->examining();

        try {
            $written = $this->leaks->handle(
                $organizationId,
                $this->leakage->handle($organizationId),
            );
        } catch (Throwable $exception) {
            return $summary->failing(new RunItem(
                ItemOutcome::Failed,
                Organization::class,
                $organizationId,
                null,
                $this->redactor->redactString($exception->getMessage()),
            ));
        }

        if ($written['raised'] === 0 && $written['cleared'] === 0) {
            return $summary->skipping();
        }

        return $summary->changing(new RunItem(
            ItemOutcome::Changed,
            Organization::class,
            $organizationId,
            null,
            sprintf(
                '%d new, %d still leaking, %d invoiced since, %d dismissed',
                $written['raised'],
                $written['kept'],
                $written['cleared'],
                $written['dismissed'],
            ),
        ));
    }

    /**
     * @return list<string>
     */
    private function sellerOrganizationIds(): array
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
