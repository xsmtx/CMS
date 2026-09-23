<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportOutcome;
use App\Infrastructure\Import\Models\ImportMapping;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one place an import writes anything.
 *
 * Three rules live here so that no mapper has to remember them.
 *
 * **A dry run is the same code path with one flag.** The flag is checked here
 * and nowhere else. A dry run that took a different path would be a dry run
 * that proves nothing, which is the failure mode every dry run has: it passes,
 * the real import fails, and the operator has already told their customers.
 *
 * **A row already mapped is skipped.** Not written twice, and not written over
 * — duplicate protection and resumability are the same index
 * (`import_mappings_unique`), and this is what reads it. A run that died on row
 * 8,000 resumes by skipping the 7,999 that came across.
 *
 * **One row is one transaction.** No silent data loss means a row is never half
 * imported: a customer whose contact insert failed is a recorded failure and no
 * customer, rather than a customer with a missing contact that nobody will ever
 * notice.
 *
 * What this class deliberately does **not** do is call a use case. An import is
 * a copy of history, not a set of new business events — see `RunImport`.
 */
final readonly class ImportWriter
{
    public function __construct(
        private ImportMode $mode,
        private string $source,
        private string $organizationId,
    ) {}

    /**
     * Whether this row has already come across.
     *
     * Asked before the mapper runs, so a resumed import does not even build the
     * attributes for seven thousand rows it is going to skip.
     */
    public function alreadyImported(ImportDomain $domain, string $externalId): bool
    {
        return $this->mapping($domain, $externalId) !== null;
    }

    /**
     * What a legacy id became, for a mapper that needs a parent.
     *
     * Null means the parent did not come across, which is a recorded failure
     * for the child rather than an orphan with a null foreign key.
     */
    public function mappedId(ImportDomain $domain, string $externalId): ?string
    {
        return $this->mapping($domain, $externalId)?->target_id;
    }

    /**
     * Write one row, or pretend to.
     *
     * The callback returns the model it created. On a dry run it is never
     * called — which is the point of the flag being here: the mapper has
     * already done its work and reported whether it *could* map the row, and
     * that is what a dry run is for.
     *
     * @param  Closure(): Model  $write
     */
    public function create(ImportDomain $domain, string $externalId, Closure $write): ImportResult
    {
        if ($this->alreadyImported($domain, $externalId)) {
            return ImportResult::skipped('Already imported.');
        }

        if (! $this->mode->writes()) {
            // Mapped successfully, written nowhere. The distinction the report
            // shows as "would create".
            return new ImportResult(ImportOutcome::Created);
        }

        // One row, one transaction, mapping included: a mapping without its
        // record would make the next run skip a row that does not exist.
        return DB::transaction(function () use ($domain, $externalId, $write): ImportResult {
            $model = $write();

            ImportMapping::query()->create([
                'organization_id' => $this->organizationId,
                'source' => $this->source,
                'domain' => $domain->value,
                'external_id' => $externalId,
                'target_type' => $model::class,
                'target_id' => (string) $model->getKey(),
            ]);

            return new ImportResult(
                ImportOutcome::Created,
                $model::class,
                (string) $model->getKey(),
            );
        });
    }

    /**
     * Whether anything from this domain has ever come across.
     *
     * Asked of the **mappings** rather than of the current run, so an operator
     * who imported the customers last week and is doing the invoices today is
     * not refused.
     */
    public function hasAnything(ImportDomain $domain): bool
    {
        return ImportMapping::query()
            ->where('source', $this->source)
            ->where('domain', $domain->value)
            ->exists();
    }

    private function mapping(ImportDomain $domain, string $externalId): ?ImportMapping
    {
        return ImportMapping::query()
            ->where('source', $this->source)
            ->where('domain', $domain->value)
            ->where('external_id', $externalId)
            ->first();
    }
}
