<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Domain\Operations\OperationState;
use App\Domain\Operations\OperationType;
use App\Infrastructure\Operations\Models\Operation;
use App\Support\Audit\Contracts\AuditLabel;
use App\Support\Correlation\CorrelationContext;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The Background Operations Center's writer.
 *
 * Every state change an operation can go through passes here, for the same
 * reason `TransitionTicket` owns a ticket's clock: two places that can set
 * `finished_at` is one too many.
 *
 * **`start()` is called before the job is dispatched, not inside it.** An
 * operation that never reaches a worker — Redis down, queue not being
 * watched, worker crashed on boot — is the failure nobody sees, because a
 * queue job that never ran leaves nothing behind. A row in `pending` that
 * is still pending an hour later is a question an operator can ask.
 *
 * **Retry backoff is bounded and exponential**, and when the attempts run
 * out the operation goes to `failed` rather than retrying forever. A
 * provider rejecting a request for a reason that will not change is not
 * improved by asking it nine hundred more times.
 *
 * **`needsIntervention()` is a first-class end state**, not a failed
 * operation with a note. It is the honest answer for a transfer the losing
 * registrar rejected: no retry will fix it, and hiding that behind a retry
 * counter wastes a week before anybody looks.
 */
final readonly class Operations
{
    public function __construct(
        private CorrelationContext $correlation,
        private SecretRedactor $redactor,
    ) {}

    public function open(
        OperationType $type,
        Model $subject,
        ?Model $actor = null,
        ?int $maxAttempts = null,
    ): Operation {
        return Operation::query()->create([
            'organization_id' => $subject->getAttribute('organization_id'),
            'type' => $type->value,
            'state' => OperationState::Pending->value,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'subject_label' => $subject instanceof AuditLabel ? $subject->auditLabel() : null,
            'actor_type' => $actor instanceof Model ? $actor::class : null,
            'actor_id' => $actor?->getKey(),
            'max_attempts' => $maxAttempts ?? (int) config('platform.operations.max_attempts', 3),
            'correlation_id' => $this->correlation->id(),
        ]);
    }

    public function running(Operation $operation): Operation
    {
        $operation->forceFill([
            'state' => OperationState::Running->value,
            'attempt' => $operation->attempt + 1,
            'started_at' => $operation->started_at ?? CarbonImmutable::now(),
            'next_attempt_at' => null,
        ])->save();

        return $operation;
    }

    public function progress(Operation $operation, int $percent): Operation
    {
        $operation->forceFill(['progress' => max(0, min(100, $percent))])->save();

        return $operation;
    }

    public function completed(Operation $operation): Operation
    {
        $operation->forceFill([
            'state' => OperationState::Completed->value,
            'progress' => 100,
            'finished_at' => CarbonImmutable::now(),
            'error' => null,
            'next_attempt_at' => null,
        ])->save();

        return $operation;
    }

    /**
     * Failed once. Whether that is the end depends on the attempts left.
     */
    public function failed(Operation $operation, Throwable|string $error): Operation
    {
        $message = $this->redactor->redactString(
            $error instanceof Throwable ? $error->getMessage() : $error,
        );

        if (! $operation->hasAttemptsLeft()) {
            $operation->forceFill([
                'state' => OperationState::Failed->value,
                'finished_at' => CarbonImmutable::now(),
                'error' => $message,
                'next_attempt_at' => null,
            ])->save();

            return $operation;
        }

        $operation->forceFill([
            'state' => OperationState::Retrying->value,
            'error' => $message,
            'next_attempt_at' => $this->backoffFrom($operation->attempt),
        ])->save();

        return $operation;
    }

    /**
     * No retry will fix this one.
     */
    public function needsIntervention(Operation $operation, Throwable|string $error): Operation
    {
        $operation->forceFill([
            'state' => OperationState::ManualIntervention->value,
            'needs_intervention' => true,
            'finished_at' => CarbonImmutable::now(),
            'next_attempt_at' => null,
            'error' => $this->redactor->redactString(
                $error instanceof Throwable ? $error->getMessage() : $error,
            ),
        ])->save();

        return $operation;
    }

    /**
     * An operator saying "I have dealt with this".
     *
     * The row is not deleted and the error is not cleared: an operation
     * that went wrong and was fixed by hand is exactly the history somebody
     * will want next quarter.
     */
    public function resolve(Operation $operation, ?Model $actor = null): Operation
    {
        $operation->forceFill([
            'resolved_at' => CarbonImmutable::now(),
            'resolved_by' => $actor?->getKey(),
            'needs_intervention' => false,
        ])->save();

        return $operation;
    }

    /**
     * Put a failed operation back in the queue by hand.
     *
     * The attempt counter is not reset. An operator retrying a thing that
     * has already failed three times should see that it is on its fourth.
     */
    public function requeue(Operation $operation): Operation
    {
        $operation->forceFill([
            'state' => OperationState::Retrying->value,
            'next_attempt_at' => CarbonImmutable::now(),
            'max_attempts' => max($operation->max_attempts, $operation->attempt + 1),
            'finished_at' => null,
            'resolved_at' => null,
            'needs_intervention' => false,
        ])->save();

        return $operation;
    }

    /**
     * Exponential, capped, and jittered by nothing.
     *
     * Deliberately not jittered: these are business operations at a rate of
     * tens per hour, not a thundering herd, and a predictable next-attempt
     * time is worth more on an operator's screen than a spread-out load.
     */
    private function backoffFrom(int $attempt): CarbonImmutable
    {
        $base = (int) config('platform.operations.retry_base_minutes', 5);
        $cap = (int) config('platform.operations.retry_cap_minutes', 240);

        return CarbonImmutable::now()->addMinutes(min($cap, $base * (2 ** max(0, $attempt - 1))));
    }
}
