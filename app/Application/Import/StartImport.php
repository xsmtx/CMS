<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportStatus;
use App\Infrastructure\Import\Jobs\RunImportJob;
use App\Infrastructure\Import\Models\ImportRun;
use App\Support\Audit\Facades\Audit;
use App\Support\Correlation\CorrelationContext;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Open the run, then hand it to a worker.
 *
 * In that order, and it is the rule ADR 0032 states: the row exists **before**
 * the job reaches the queue, because an import that never reached a worker is
 * the failure nobody sees — and an import is exactly the operation somebody
 * starts and then goes to lunch.
 *
 * The counts the source reported are stored on the run, so the report can say
 * "4,182 of 4,190" rather than only the number that worked. A report that shows
 * successes without the denominator cannot tell an operator whether anything was
 * missed.
 */
final readonly class StartImport
{
    public function __construct(
        private ImportSourceRegistry $sources,
        private OrganizationContext $organizations,
        private CorrelationContext $correlation,
    ) {}

    /**
     * @param  list<ImportDomain>  $domains
     */
    public function handle(
        string $sourceKey,
        ImportMode $mode,
        array $domains,
        ?Model $actor = null,
    ): ImportRun {
        $source = $this->sources->for($sourceKey);

        if ($source === null) {
            throw new ImportRefused("This installation has no import source called [{$sourceKey}].");
        }

        if ($domains === []) {
            throw new ImportRefused('No domains were chosen, so there is nothing to import.');
        }

        $problems = $source->check();

        if ($problems !== []) {
            // Refused before a run row exists: a failed run with nothing in it
            // would be a row an operator has to interpret, and the problems are
            // already sentences they can act on.
            throw new ImportRefused(implode(' ', $problems));
        }

        $organizationId = $this->organizations->id();

        if ($organizationId === null) {
            throw new ImportRefused('An import needs an organization to import into.');
        }

        $run = ImportRun::query()->create([
            'organization_id' => $organizationId,
            'source' => $source->key(),
            'mode' => $mode->value,
            'status' => ImportStatus::Pending->value,
            'domains' => array_map(
                static fn (ImportDomain $domain): string => $domain->value,
                $domains,
            ),
            // The denominator, so the report can say "4,182 of 4,190".
            'expected' => $source->counts(),
            'started_by' => $actor?->getKey(),
            'correlation_id' => $this->correlation->idOrGenerate(),
        ]);

        Audit::action('import.started')
            ->by($actor)
            ->on($run)
            ->forOrganization($organizationId)
            ->withMetadata([
                'source' => $source->key(),
                'mode' => $mode->value,
                'domains' => $run->domains,
            ])
            ->write();

        // The row exists; now the worker.
        dispatch(new RunImportJob($run->id, $organizationId, $run->correlation_id));

        return $run;
    }
}
