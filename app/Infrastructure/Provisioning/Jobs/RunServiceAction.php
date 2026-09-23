<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Jobs;

use App\Application\Provisioning\RecordServiceEvent;
use App\Application\Provisioning\RunServiceOperation;
use App\Domain\Operations\Contracts\ReportsToOperations;
use App\Domain\Provisioning\OperationOutcome;
use App\Domain\Provisioning\ServiceOperation;
use App\Infrastructure\Operations\Concerns\RecordsOperation;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Correlation\CorrelationContext;
use App\Support\Correlation\CorrelationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Suspend, unsuspend, terminate or sync — one job for all four.
 *
 * They differ only in which method of the runner they call and which
 * permission let somebody ask. Four near-identical classes would drift:
 * the retry policy would be fixed in one and forgotten in another, and the
 * failure hook would exist in two of them.
 *
 * Unique per service **and operation**: a suspend and a sync may run
 * alongside each other, two suspends may not.
 */
final class RunServiceAction implements ReportsToOperations, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RecordsOperation;
    use SerializesModels;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $serviceId,
        public readonly ServiceOperation $operation,
        public readonly ?string $reason = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue('provisioning');
    }

    public function uniqueId(): string
    {
        return $this->serviceId.':'.$this->operation->value;
    }

    public function tries(): int
    {
        return (int) config('platform.provisioning.job_tries', 3);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(RunServiceOperation $operations, CorrelationContext $correlation): void
    {
        $carried = CorrelationId::tryFrom($this->correlationId);

        if ($carried instanceof CorrelationId) {
            $correlation->set($carried);
        }

        $this->markRunning();

        $service = $this->service();

        if ($service === null) {
            $this->markCompleted();

            return;
        }

        match ($this->operation) {
            ServiceOperation::Suspend => $operations->suspend($service, $this->reason),
            ServiceOperation::Unsuspend => $operations->unsuspend($service),
            ServiceOperation::Terminate => $operations->terminate($service),
            ServiceOperation::Sync => $operations->sync($service),
            // Creating is its own job, with its own uniqueness and its own
            // failure state. Everything else is a no-op here rather than a
            // surprise.
            default => null,
        };

        $this->markCompleted();
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception?->getMessage() ?? (string) __('provisioning.errors.job_failed'));

        $service = $this->service();

        if ($service === null) {
            return;
        }

        // The service keeps whatever status it had: a suspend that did not
        // happen leaves the account running, and saying otherwise would be
        // a lie an operator acts on.
        app(RecordServiceEvent::class)->handle(
            $service,
            $this->operation,
            OperationOutcome::Failed,
            $exception?->getMessage() ?? (string) __('provisioning.errors.job_failed'),
        );
    }

    private function service(): ?Service
    {
        return Service::query()
            ->withoutGlobalScope('organization')
            ->with(['server'])
            ->find($this->serviceId);
    }
}
