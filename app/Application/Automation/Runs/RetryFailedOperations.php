<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Operations\Operations;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Operations\OperationType;
use App\Infrastructure\Domains\Jobs\RegisterDomain;
use App\Infrastructure\Operations\Models\Operation;
use App\Infrastructure\Provisioning\Jobs\ProvisionService;
use App\Support\Correlation\CorrelationContext;
use App\Support\Organizations\OrganizationContext;
use Throwable;

/**
 * Puts operations that are waiting to be retried back in the queue.
 *
 * The sweep exists because a delayed job is a promise held by Redis, and a
 * Redis that was flushed has quietly dropped every one of them. A row in
 * `operations` with a `next_attempt_at` in the past survives that, which is
 * the whole argument for the Operations Center being a table rather than a
 * queue inspection.
 *
 * Only operations with attempts left are picked up; `Operations::failed()`
 * has already decided the difference. An operation in
 * `manual_intervention` is never retried automatically — that is what the
 * state means.
 *
 * `ChangePackage` is not re-dispatched. Its job carries the package it was
 * asked for, and that argument is not on the operation row; retrying it
 * from here would guess. An operator retries it from the service screen,
 * where the package is in front of them.
 */
final readonly class RetryFailedOperations implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private Operations $operations,
        private CorrelationContext $correlation,
    ) {}

    public function handle(): RunSummary
    {
        $summary = new RunSummary;

        foreach ($this->due() as $operation) {
            $summary = $summary->examining();

            try {
                $job = $this->jobFor($operation);

                if ($job === null) {
                    $summary = $summary->skipping();

                    continue;
                }

                dispatch($job->withOperation($operation->id));

                $summary = $summary->changing(new RunItem(
                    ItemOutcome::Changed,
                    Operation::class,
                    $operation->id,
                    $operation->subject_label ?? $operation->type->value,
                    'attempt '.($operation->attempt + 1),
                ));
            } catch (Throwable $exception) {
                $this->operations->needsIntervention($operation, $exception);

                $summary = $summary->failing(new RunItem(
                    ItemOutcome::Failed,
                    Operation::class,
                    $operation->id,
                    $operation->subject_label ?? $operation->type->value,
                    $exception->getMessage(),
                ));
            }
        }

        return $summary;
    }

    private function jobFor(Operation $operation): ProvisionService|RegisterDomain|null
    {
        $subjectId = $operation->subject_id;

        if ($subjectId === null) {
            return null;
        }

        $correlationId = $operation->correlation_id ?? $this->correlation->id();

        return match ($operation->type) {
            OperationType::ServiceProvision => new ProvisionService($subjectId, $correlationId),
            OperationType::DomainRegister => new RegisterDomain($subjectId, $correlationId),
            // A service action carries a reason and a domain action carries
            // nameservers or a flag; neither is on the operation row, so
            // neither can be reconstructed here without inventing one.
            default => null,
        };
    }

    /**
     * @return list<Operation>
     */
    private function due(): array
    {
        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(Operation::query()
                ->dueForRetry()
                ->orderBy('next_attempt_at')
                ->limit(100)
                ->get()
                ->all()),
        );
    }
}
