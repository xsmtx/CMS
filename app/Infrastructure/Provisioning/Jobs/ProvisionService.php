<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Jobs;

use App\Application\Provisioning\RecordServiceEvent;
use App\Application\Provisioning\RunServiceOperation;
use App\Application\Provisioning\TransitionService;
use App\Domain\Provisioning\OperationOutcome;
use App\Domain\Provisioning\ServiceOperation;
use App\Domain\Provisioning\ServiceStatus;
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
 * Sets one service up.
 *
 * The first real work this platform's queue carries, and it sets the shape
 * the rest follow:
 *
 * - **Unique on the service.** Two paid-order events for the same purchase
 *   must not run two provisioning attempts side by side. The uniqueness
 *   lock is held for the length of the attempt, not for the lifetime of
 *   the service.
 *
 * - **Bounded tries with backoff.** A control panel rebooting is worth
 *   waiting for; one that has been misconfigured for a week is not. After
 *   the last try, `failed()` puts the service in `failed` with the reason,
 *   rather than leaving it in `provisioning` forever — which is the state
 *   that makes an operator distrust the whole screen.
 *
 * - **The correlation id travels with it.** The request that placed the
 *   order and the worker that provisioned it appear under the same id in
 *   the log, which is the only way to answer "what happened to this
 *   customer" without guessing.
 *
 * The service id is carried rather than the model: a serialised model is a
 * snapshot, and by the time a retry runs the row has moved on.
 */
final class ProvisionService implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How long the uniqueness lock survives if a worker dies holding it.
     * Long enough for a slow control panel, short enough that a crashed
     * worker does not block the retry.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $serviceId,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue('provisioning');
    }

    public function uniqueId(): string
    {
        return $this->serviceId;
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
        // A control panel that is rebooting comes back in a minute; one
        // that is wedged does not come back in ten.
        return [30, 120, 600];
    }

    public function handle(
        RunServiceOperation $operations,
        CorrelationContext $correlation,
    ): void {
        $carried = CorrelationId::tryFrom($this->correlationId);

        if ($carried instanceof CorrelationId) {
            // The request that placed the order and the worker that
            // provisioned it appear under one id in the log.
            $correlation->set($carried);
        }

        $service = $this->service();

        if ($service === null) {
            // Deleted between dispatch and now. Nothing to do and nothing
            // wrong.
            return;
        }

        if (! $service->status->canProvision()) {
            // Already set up, or somebody terminated it while this sat in
            // the queue. A second account is the one outcome worth
            // preventing at any cost.
            return;
        }

        $operations->provision($service);
    }

    /**
     * The last word, after every try has been used.
     *
     * Without this the service sits in `provisioning` forever and an
     * operator has to infer from a Horizon dashboard that something broke.
     */
    public function failed(?Throwable $exception): void
    {
        $service = $this->service();

        if ($service === null) {
            return;
        }

        $message = $exception?->getMessage() ?? (string) __('provisioning.errors.job_failed');

        app(RecordServiceEvent::class)->handle(
            $service,
            ServiceOperation::Create,
            OperationOutcome::Failed,
            $message,
        );

        if ($service->status->canTransitionTo(ServiceStatus::Failed)) {
            app(TransitionService::class)->handle($service, ServiceStatus::Failed, null, $message);
        }
    }

    private function service(): ?Service
    {
        // Outside the boundary on purpose: a queue worker acts for the
        // platform, not for an organization, and there is no request to
        // take a boundary from.
        return Service::query()
            ->withoutGlobalScope('organization')
            ->with(['customer', 'server', 'product.serverGroup'])
            ->find($this->serviceId);
    }
}
