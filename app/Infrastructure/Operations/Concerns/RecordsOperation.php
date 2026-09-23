<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Concerns;

use App\Application\Operations\Operations;
use App\Infrastructure\Operations\Models\Operation;
use Throwable;

/**
 * Lets a queued job report to the Background Operations Center.
 *
 * The operation row is opened by `WatchedDispatch` **before** the job is
 * dispatched, and its id travels on the job. A job that never reaches a
 * worker therefore leaves a `pending` row an operator can see, which is
 * the whole point — a queue job that never ran leaves nothing at all.
 *
 * `operationId` is a plain mutable property rather than a constructor
 * argument so that adding the Operations Center to a job does not change
 * the job's signature, and so that a job dispatched directly (in a test,
 * or from a console command) simply has none and records nothing.
 */
trait RecordsOperation
{
    public ?string $operationId = null;

    public function withOperation(string $operationId): static
    {
        $this->operationId = $operationId;

        return $this;
    }

    protected function markRunning(): void
    {
        $operation = $this->operation();

        if ($operation instanceof Operation) {
            app(Operations::class)->running($operation);
        }
    }

    protected function markCompleted(): void
    {
        $operation = $this->operation();

        if ($operation instanceof Operation) {
            app(Operations::class)->completed($operation);
        }
    }

    /**
     * Nothing a retry can fix. A domain with no registrar is waiting for a
     * person, and a retry loop would hide that for a week.
     */
    protected function markNeedingIntervention(Throwable|string $error): void
    {
        $operation = $this->operation();

        if ($operation instanceof Operation) {
            app(Operations::class)->needsIntervention($operation, $error);
        }
    }

    protected function markFailed(Throwable|string $error): void
    {
        $operation = $this->operation();

        if ($operation instanceof Operation) {
            app(Operations::class)->failed($operation, $error);
        }
    }

    /**
     * Nothing a worker reads is inside a boundary: a worker acts for the
     * platform and has no request to take one from.
     */
    private function operation(): ?Operation
    {
        if ($this->operationId === null) {
            return null;
        }

        return Operation::query()
            ->withoutGlobalScope('organization')
            ->find($this->operationId);
    }
}
