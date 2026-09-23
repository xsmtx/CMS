<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Application\Provisioning\TransitionService;
use App\Domain\Crm\CancellationStatus;
use App\Domain\Crm\CancellationType;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\CancellationRequest;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Act on a cancellation request, or put it down.
 *
 * Two outcomes, and the difference matters to more than this queue.
 * **Completing** an immediate request terminates the service now;
 * completing an end-of-term one marks it `cancel_pending`, which is the
 * state that stops the renewal sweep invoicing another term while leaving
 * the customer what they have already paid for. **Withdrawing** is the
 * customer changing their mind, and it is deliberately not the same record
 * as completing: a churn report that counted them together would overstate
 * every month.
 *
 * The service's status is moved through `TransitionService`, the one place
 * that moves one — so the addons follow, the audit record is written and
 * the enum decides what is allowed, exactly as they do everywhere else.
 */
final readonly class CompleteCancellation
{
    public function __construct(private TransitionService $services) {}

    public function complete(CancellationRequest $request, ?Model $actor = null): CancellationRequest
    {
        $service = $request->service;

        if ($service instanceof Service) {
            $next = $request->type === CancellationType::Immediate
                ? ServiceStatus::Terminated
                : ServiceStatus::CancelPending;

            // Already there is not a failure: an operator who terminated
            // the service by hand first has done the work, and the queue
            // item still needs closing.
            if ($service->status !== $next && $service->status->canTransitionTo($next)) {
                $this->services->handle($service, $next, $actor, $request->reason);
            }
        }

        return $this->close($request, CancellationStatus::Completed, $actor);
    }

    public function withdraw(CancellationRequest $request, ?Model $actor = null): CancellationRequest
    {
        $service = $request->service;

        // A service already marked as going away is put back. Anything
        // terminated is not: that account is gone at the provider, and a
        // withdrawal cannot un-delete somebody's data.
        if ($service instanceof Service && $service->status === ServiceStatus::CancelPending) {
            $this->services->handle($service, ServiceStatus::Active, $actor);
        }

        return $this->close($request, CancellationStatus::Withdrawn, $actor);
    }

    private function close(
        CancellationRequest $request,
        CancellationStatus $status,
        ?Model $actor,
    ): CancellationRequest {
        $request->forceFill([
            'status' => $status->value,
            'completed_at' => CarbonImmutable::now(),
            'completed_by' => $actor?->getAttribute('name'),
        ])->save();

        Audit::action('crm.cancellation.'.$status->value)
            ->by($actor)
            ->on($request)
            ->forOrganization($request->organization_id)
            ->because($request->reason)
            ->withMetadata(['type' => $request->type->value])
            ->write();

        return $request;
    }
}
