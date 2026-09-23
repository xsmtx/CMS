<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Operations\WatchedDispatch;
use App\Domain\Operations\OperationType;
use App\Domain\Provisioning\ServiceOperation;
use App\Domain\Provisioning\ServiceStatus;
use App\Http\Api\ApiResource;
use App\Http\Api\QueryOptions;
use App\Infrastructure\Provisioning\Jobs\RunServiceAction;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Correlation\CorrelationContext;
use App\Support\Errors\ValidationFailedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's hosting accounts.
 *
 * The action endpoint is the reason this API exists at all: a control panel
 * somebody built for their own customers needs to suspend an account
 * without a person clicking. It queues the same job the admin screen
 * queues, through the same `WatchedDispatch`, so the work appears in the
 * Background Operations Center whether a person or a script asked for it.
 *
 * `terminate` is **not** reachable here. Destroying an account is not
 * something a token should be able to do while its owner is asleep, and an
 * integration that genuinely needs it can say so and get its own decision.
 */
final class ServiceController extends ApiController
{
    /**
     * The actions a token may take. Listed rather than derived from
     * `ServiceOperation`, so adding an operation to the platform does not
     * silently add it to the public API.
     */
    private const array ALLOWED = ['suspend', 'unsuspend'];

    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            filters: ['status' => 'status', 'module' => 'module'],
            sorts: ['created_at', 'name', 'next_due_on'],
        );

        $services = $options
            ->applyTo($this->customer->owned(Service::query()), $request)
            ->paginate($options->perPage($request));

        return new JsonResponse(ApiResource::page(
            $services,
            array_values(array_map($this->row(...), $services->items())),
        ));
    }

    public function show(string $service): JsonResponse
    {
        /** @var Service $record */
        $record = $this->customer->find(Service::query()->whereKey($service));

        return new JsonResponse(ApiResource::item([
            ...$this->row($record),
            'username' => $record->username,
            'configuration' => $record->configuration,
            'starts_on' => $record->starts_on?->toIso8601String(),
            'provisioned_at' => $record->provisioned_at?->toIso8601String(),
            // Never the password. It is encrypted at rest and hidden on the
            // model, and an API that returned it would make every token a
            // control-panel credential.
        ]));
    }

    public function action(
        Request $request,
        string $service,
        string $action,
        WatchedDispatch $dispatcher,
        CorrelationContext $correlation,
    ): JsonResponse {
        if (! in_array($action, self::ALLOWED, true)) {
            throw new ValidationFailedException(
                (string) __('api.errors.unknown_action', ['action' => $action]),
                ['action' => [(string) __('api.errors.allowed_actions', [
                    'actions' => implode(', ', self::ALLOWED),
                ])]],
            );
        }

        /** @var Service $record */
        $record = $this->customer->find(Service::query()->whereKey($service));

        $operation = ServiceOperation::from($action);

        $dispatcher->handle(
            $action === 'suspend' ? OperationType::ServiceSuspend : OperationType::ServiceUnsuspend,
            $record,
            new RunServiceAction(
                $record->id,
                $operation,
                $request->string('reason')->toString() ?: null,
                $correlation->id(),
            ),
            $this->customer->contact(),
        );

        // 202: the work is queued, not done. Saying 200 here would be a lie
        // a client would write code against.
        return new JsonResponse(
            ApiResource::item([
                'id' => $record->id,
                'action' => $action,
                'status' => 'queued',
            ]),
            JsonResponse::HTTP_ACCEPTED,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'status' => $service->status->value,
            'is_active' => $service->status === ServiceStatus::Active,
            'domain' => $service->domain,
            'billing_cycle' => $service->billing_cycle?->value,
            'recurring' => ApiResource::money($service->recurring),
            'next_due_on' => $service->next_due_on?->toDateString(),
            'created_at' => $service->created_at?->toIso8601String(),
        ];
    }
}
