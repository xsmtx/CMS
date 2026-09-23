<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Import\Contracts\ImportSource;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRecord;
use App\Domain\Import\ImportStatus;
use App\Infrastructure\Import\Models\ImportItem;
use App\Infrastructure\Import\Models\ImportRun;
use App\Support\Audit\Facades\Audit;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The pipeline: Analyze → Map → Validate → Import → Reconcile → Report.
 *
 * **An import writes rows and dispatches nothing.** It is a copy of history, not
 * a set of new business events. `IssueInvoice` would allocate a fresh number and
 * lose the one the customer has on paper; `TransitionOrder` announcing
 * `OrderPaid` for two years of history would provision two years of services and
 * email every customer the platform has just acquired; `RecordPayment` would
 * recompute a balance that is already settled. So the mappers write through
 * Eloquent, deliberately, and each one says why in its own docblock.
 *
 * Four rules, and they are the ones every sweep in this platform follows
 * (ADR 0031):
 *
 * - **Domains run in dependency order**, which `ImportDomain::ordered()` states
 *   once. A service belongs to a customer; a customer imported after it has
 *   nothing to attach to.
 * - **A domain whose parent has never been imported is refused before anything
 *   is written**, with the missing domain named. Running it would produce
 *   thousands of orphans, all of them recorded as failures, and an operator who
 *   has to read twelve thousand identical failures will not.
 * - **One row failing never stops the run**, and every failure is recorded with
 *   its external id, a label somebody recognises and a sanitised reason.
 * - **`completed` means the run finished**, not that every row succeeded. A
 *   migration with four hundred failures out of twelve thousand completed; the
 *   report is what says what to do next.
 */
final readonly class RunImport
{
    public function __construct(
        private ImportMapperRegistry $mappers,
        private SecretRedactor $redactor,
    ) {}

    /**
     * Read, map, and — unless this is a dry run — write.
     */
    public function handle(ImportRun $run, ImportSource $source): ImportRun
    {
        $run->forceFill([
            'status' => ImportStatus::Running->value,
            'started_at' => CarbonImmutable::now(),
        ])->save();

        try {
            $totals = $this->pipeline($run, $source);
        } catch (Throwable $exception) {
            // The run could not proceed at all: the legacy database went away,
            // or a domain was asked for whose parent was never imported. The
            // reason reaches the operator; the exception reaches the log.
            Log::warning('import.run_failed', [
                'run_id' => $run->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $run->forceFill([
                'status' => ImportStatus::Failed->value,
                'error' => $exception->getMessage(),
                'finished_at' => CarbonImmutable::now(),
            ])->save();

            return $run->fresh() ?? $run;
        }

        $run->forceFill([
            'status' => ImportStatus::Completed->value,
            'totals' => $totals,
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        Audit::action('import.completed')
            ->on($run)
            ->forOrganization($run->organization_id)
            ->withMetadata([
                'source' => $run->source,
                'mode' => $run->mode->value,
                'totals' => $totals,
            ])
            ->write();

        return $run->fresh() ?? $run;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function pipeline(ImportRun $run, ImportSource $source): array
    {
        $asked = $this->asked($run);

        $this->refuseOrphans($run, $asked);

        $writer = new ImportWriter($run->mode, $source->key(), $run->organization_id);

        $totals = [];

        // Dependency order, stated once in the enum and read here.
        foreach (ImportDomain::ordered() as $domain) {
            if (! in_array($domain, $asked, strict: true)) {
                continue;
            }

            $totals[$domain->value] = $this->runDomain($run, $source, $writer, $domain);
        }

        return $totals;
    }

    /**
     * @return array<string, int>
     */
    private function runDomain(
        ImportRun $run,
        ImportSource $source,
        ImportWriter $writer,
        ImportDomain $domain,
    ): array {
        $mapper = $this->mappers->for($domain);

        $counts = [
            ImportOutcome::Created->value => 0,
            ImportOutcome::Updated->value => 0,
            ImportOutcome::Skipped->value => 0,
            ImportOutcome::Failed->value => 0,
        ];

        foreach ($source->read($domain) as $record) {
            $result = $this->mapOne($mapper, $record, $writer);

            $counts[$result->outcome->value]++;

            $this->record($run, $domain, $record, $result);
        }

        return $counts;
    }

    /**
     * One row, and nothing it does stops the next one.
     */
    private function mapOne(ImportMapper $mapper, ImportRecord $record, ImportWriter $writer): ImportResult
    {
        try {
            return $mapper->map($record, $writer);
        } catch (Throwable $exception) {
            // A mapper returns a failure when the *row* is the problem. Reaching
            // here means the mapper itself broke, which is still one row: an
            // import that stopped on row 4,000 of 12,000 leaves an operator with
            // no idea what came across.
            Log::warning('import.row_failed', [
                'domain' => $record->domain->value,
                'external_id' => $record->externalId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return ImportResult::failed($this->sanitise($exception->getMessage()));
        }
    }

    /**
     * One item row per legacy row, kept rather than summarised.
     *
     * No silent data loss means an operator can read the four hundred rows that
     * did not come across and decide about each. A run that stored only totals
     * would tell them the number, which is the least useful part.
     */
    private function record(
        ImportRun $run,
        ImportDomain $domain,
        ImportRecord $record,
        ImportResult $result,
    ): void {
        ImportItem::query()->create([
            'organization_id' => $run->organization_id,
            'run_id' => $run->id,
            'domain' => $domain->value,
            'external_id' => $record->externalId,
            'outcome' => $result->outcome->value,
            'target_type' => $result->targetType,
            'target_id' => $result->targetId,
            'label' => $record->label,
            'message' => $result->message,
        ]);
    }

    /**
     * Refuse a partial import whose parents are missing.
     *
     * Checked against the **mappings**, not against this run: an operator who
     * brought the customers across last week and is doing the invoices today is
     * the normal case, and a check that only looked at the current run would
     * refuse it.
     *
     * @param  list<ImportDomain>  $asked
     */
    private function refuseOrphans(ImportRun $run, array $asked): void
    {
        $writer = new ImportWriter($run->mode, $run->source, $run->organization_id);

        foreach ($asked as $domain) {
            foreach ($domain->requires() as $required) {
                if (in_array($required, $asked, strict: true)) {
                    continue;
                }

                if (! $writer->hasAnything($required)) {
                    throw new ImportRefused(sprintf(
                        'Import [%s] first: nothing from [%s] has been imported, so every %s row would be an orphan.',
                        $required->value,
                        $required->value,
                        $domain->value,
                    ));
                }
            }
        }
    }

    /**
     * @return list<ImportDomain>
     */
    private function asked(ImportRun $run): array
    {
        $asked = [];

        foreach ($run->domains as $value) {
            $domain = ImportDomain::tryFrom((string) $value);

            if ($domain instanceof ImportDomain) {
                $asked[] = $domain;
            }
        }

        return $asked;
    }

    /**
     * A message safe to show an operator.
     *
     * A legacy connection string or a password can appear in a driver's
     * exception message, and that message is about to be written to a row
     * somebody will read on a screen.
     */
    private function sanitise(string $message): string
    {
        $redacted = $this->redactor->redact(['message' => $message]);

        return is_string($redacted['message'] ?? null) ? $redacted['message'] : 'The row could not be imported.';
    }
}
