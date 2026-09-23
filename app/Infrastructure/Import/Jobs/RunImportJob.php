<?php

declare(strict_types=1);

namespace App\Infrastructure\Import\Jobs;

use App\Application\Import\ImportSourceRegistry;
use App\Application\Import\RunImport;
use App\Domain\Import\ImportStatus;
use App\Infrastructure\Import\Models\ImportRun;
use App\Support\Correlation\CorrelationContext;
use App\Support\Correlation\CorrelationId;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * An import, on a worker.
 *
 * A twelve-thousand-row migration is not an HTTP request, and it is exactly the
 * operation somebody starts and then goes to lunch — so the run row is opened
 * before this job is handed to the queue (ADR 0032) and the screen can show it
 * as pending.
 *
 * **One attempt.** A migration that retried itself would resume from the mapping
 * table and be correct, which sounds fine until the reason it failed was the
 * legacy database being overloaded by the first attempt. An operator pressing
 * the button again is the right retry, because they can see what happened first.
 *
 * The timeout is long and deliberate: an import is measured in minutes.
 */
final class RunImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Long, because an import is measured in minutes rather than seconds. */
    public int $timeout = 3600;

    public function __construct(
        private readonly string $runId,
        private readonly string $organizationId,
        private readonly ?string $correlationId = null,
    ) {
        $this->onQueue('imports');
    }

    public function handle(
        RunImport $import,
        ImportSourceRegistry $sources,
        OrganizationContext $organizations,
        CorrelationContext $correlation,
    ): void {
        $carried = $this->correlationId === null
            ? null
            : CorrelationId::tryFrom($this->correlationId);

        if ($carried instanceof CorrelationId) {
            // The same string the operator's request carried, so a support
            // conversation about an import can be anchored to one identifier
            // across the screen, the job and the legacy database's own log.
            $correlation->set($carried);
        }

        // The run belongs to an organization and everything it writes has to
        // land in that one, not in whatever a worker happened to have set.
        $organizations->runAs($this->organizationId, function () use ($import, $sources): void {
            $run = ImportRun::query()->find($this->runId);

            if ($run === null || $run->status !== ImportStatus::Pending) {
                // Already handled, or the run was deleted. Not an error: a job
                // that ran twice must not import twice, and the mapping table
                // would make that harmless anyway.
                return;
            }

            $source = $sources->for($run->source);

            if ($source === null) {
                $run->forceFill([
                    'status' => ImportStatus::Failed->value,
                    'error' => 'This installation has no import source called ['.$run->source.'].',
                    'finished_at' => now(),
                ])->save();

                return;
            }

            $import->handle($run, $source);
        });
    }
}
