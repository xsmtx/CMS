<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Domain\Operations\Contracts\ReportsToOperations;
use App\Domain\Operations\OperationType;
use App\Infrastructure\Operations\Models\Operation;
use Illuminate\Database\Eloquent\Model;

/**
 * Opens an operation, then dispatches the job that does the work.
 *
 * In that order, and the order is the decision: the row exists before
 * anything is handed to a queue, so an operation that never reaches a
 * worker is still visible. Redis being down, a worker that will not boot
 * and a supervisor watching the wrong queue all look identical from inside
 * a job — because none of them ever gets there.
 *
 * A job that does not implement `ReportsToOperations` is dispatched
 * unwatched rather than refused. Not every queued thing is a business
 * operation, and a mail send does not belong on this screen.
 */
final readonly class WatchedDispatch
{
    public function __construct(private Operations $operations) {}

    public function handle(
        OperationType $type,
        Model $subject,
        object $job,
        ?Model $actor = null,
    ): ?Operation {
        if (! $job instanceof ReportsToOperations) {
            dispatch($job);

            return null;
        }

        $operation = $this->operations->open($type, $subject, $actor);

        dispatch($job->withOperation($operation->id));

        return $operation;
    }
}
