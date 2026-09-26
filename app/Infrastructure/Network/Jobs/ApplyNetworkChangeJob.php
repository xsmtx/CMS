<?php

declare(strict_types=1);

namespace App\Infrastructure\Network\Jobs;

use App\Application\Network\ApplyNetworkChange;
use App\Domain\Network\NetworkChangeState;
use App\Domain\Operations\Contracts\ReportsToOperations;
use App\Infrastructure\Network\Models\NetworkChange;
use App\Infrastructure\Operations\Concerns\RecordsOperation;
use App\Support\Correlation\CorrelationContext;
use App\Support\Correlation\CorrelationId;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Pushes one approved change to its device.
 *
 * **`tries` is one, and that is the opposite of every other job here.**
 * Provisioning retries because creating an account twice is harmless — the
 * second attempt finds it already done and says so (ADR 0026). A device
 * configuration is not idempotent in that way: a retry after a timeout might
 * be applying a configuration that already went on, to a box that has since
 * been rolled back by the verify step. A failed apply is a record an operator
 * reads and decides about, never something this platform quietly tries again.
 *
 * Unique per change for the same reason, and `uniqueFor` is long: two workers
 * applying one change is the thing that must not happen even if a queue
 * hiccups.
 *
 * The boundary is set from the change's own organization. A queued job carries
 * no session, so the global scope would otherwise find nothing and the run
 * would look like a change that had vanished.
 */
final class ApplyNetworkChangeJob implements ReportsToOperations, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RecordsOperation;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $changeId,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue('network');
    }

    public function uniqueId(): string
    {
        return $this->changeId;
    }

    public function tries(): int
    {
        return 1;
    }

    public function handle(
        ApplyNetworkChange $apply,
        OrganizationContext $organizations,
        CorrelationContext $correlation,
    ): void {
        $carried = CorrelationId::tryFrom($this->correlationId);

        if ($carried instanceof CorrelationId) {
            $correlation->set($carried);
        }

        $change = $organizations->withoutBoundary(
            fn (): ?NetworkChange => NetworkChange::query()
                ->withoutGlobalScope('organization')
                ->with('device')
                ->find($this->changeId),
        );

        if (! $change instanceof NetworkChange) {
            $this->markCompleted();

            return;
        }

        $organizations->set($change->organization_id);

        $this->markRunning();

        $applied = $apply->handle($change);

        // The operation follows the record rather than the absence of an
        // exception: `ApplyNetworkChange` catches its own failures so that the
        // change carries the reason, and a job that reported success because
        // nothing was thrown would contradict the row beside it.
        $applied->state === NetworkChangeState::Completed
            ? $this->markCompleted()
            : $this->markFailed($applied->result ?? 'The change did not complete.');
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailed($exception->getMessage());
    }
}
