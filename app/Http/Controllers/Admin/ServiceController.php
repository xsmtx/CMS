<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Operations\WatchedDispatch;
use App\Application\Provisioning\SearchServices;
use App\Application\Provisioning\TransitionService;
use App\Domain\Operations\OperationType;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Provisioning\ServiceOperation;
use App\Domain\Provisioning\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Provisioning\ServiceActionRequest;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Provisioning\Jobs\ProvisionService as ProvisionServiceJob;
use App\Infrastructure\Provisioning\Jobs\RunServiceAction;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceEvent;
use App\Infrastructure\Provisioning\Models\ServiceOption;
use App\Infrastructure\Provisioning\Models\ServicePlacement;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Support\Correlation\CorrelationContext;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything customers are running.
 *
 * Operations are **queued**, never run inside the request. A control panel
 * that takes forty seconds would otherwise hold a web worker, and an
 * operator who refreshes because nothing happened would send a second
 * request while the first was still talking to the provider.
 */
final class ServiceController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly ModuleRegistry $modules,
        private readonly CorrelationContext $correlation,
        private readonly WatchedDispatch $dispatcher,
    ) {}

    /**
     * The products and services list, the shape a WHMCS operator knows.
     *
     * A filter panel that folds away, a row of product types across the
     * top to drill into, a toggle for closed accounts, and a row that
     * opens to show the rest rather than a second page load.
     */
    public function index(Request $request, SearchServices $search): Response
    {
        $this->authorize('viewAny', Service::class);

        /** @var array<string, mixed> $criteria */
        $criteria = $request->only([
            'product_type', 'server', 'product', 'gateway',
            'billing_cycle', 'status', 'domain', 'client',
            'custom_field', 'custom_value',
        ]);

        // Hidden unless asked for, which is the WHMCS default and the
        // right one: a closed account's services are a record, not work.
        $includeInactive = $request->boolean('inactive');

        $services = $search->paginate($criteria, includeInactiveClients: $includeInactive);

        return Inertia::render('Admin/Services/Index', [
            'services' => [
                'data' => array_map($this->row(...), $services->items()),
                'currentPage' => $services->currentPage(),
                'lastPage' => $services->lastPage(),
                'total' => $services->total(),
                'links' => $services->linkCollection()->all(),
            ],
            'filters' => [...$criteria, 'inactive' => $includeInactive],
            'schema' => $search->schema(),
            'types' => $search->typeCounts($includeInactive),
            'counts' => [
                'pending' => Service::query()->where('status', ServiceStatus::Pending->value)->count(),
                'failed' => Service::query()->where('status', ServiceStatus::Failed->value)->count(),
                'suspended' => Service::query()->where('status', ServiceStatus::Suspended->value)->count(),
            ],
        ]);
    }

    public function show(Service $service): Response
    {
        $this->authorize('view', $service);

        $service->load([...Customer::displayNameWith('customer'), 'server.group', 'product', 'options', 'order']);

        $module = $service->module === null ? null : $this->modules->find($service->module);
        $capabilities = $module instanceof ProvisioningModule ? $module->capabilities() : null;

        return Inertia::render('Admin/Services/Show', [
            'service' => [
                ...$this->row($service),
                'package' => $service->package,
                'module' => $service->module,
                'externalId' => $service->external_id,
                'username' => $service->username,
                'orderNumber' => $service->order?->number,
                'startsOn' => $service->starts_on?->toDateString(),
                'provisionedAt' => $service->provisioned_at?->toIso8601String(),
                'suspensionReason' => $service->suspension_reason,
                'failureReason' => $service->failure_reason,
                'syncedAt' => $service->synced_at?->toIso8601String(),
                'options' => $service->options
                    ->map(fn (ServiceOption $option): array => [
                        'group' => $option->group_name,
                        'label' => $option->label,
                    ])
                    ->values()
                    ->all(),
                'events' => $service->events()->limit(50)->get()
                    ->map(fn (ServiceEvent $event): array => [
                        'id' => $event->id,
                        'operation' => (string) __($event->operation->labelKey()),
                        'outcome' => $event->outcome->value,
                        'outcomeLabel' => (string) __($event->outcome->labelKey()),
                        'actor' => $event->actor_label,
                        'message' => $event->message,
                        'occurredAt' => $event->occurred_at->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'transitions' => array_map(
                    static fn (ServiceStatus $status): array => [
                        'value' => $status->value,
                        'label' => (string) __($status->labelKey()),
                    ],
                    $service->status->manualTransitions(),
                ),
            ],
            'placement' => $this->placement($service),
            'can' => [
                'update' => $this->actor->can('update', $service),
                'provision' => $this->actor->can('provision', $service)
                    && $service->status->canProvision(),
                'suspend' => $this->actor->can('suspend', $service)
                    && ($capabilities !== null && $capabilities->suspend),
                'unsuspend' => $this->actor->can('suspend', $service)
                    && ($capabilities !== null && $capabilities->unsuspend),
                'terminate' => $this->actor->can('terminate', $service)
                    && ($capabilities !== null && $capabilities->terminate),
                'sync' => $this->actor->can('update', $service) && ($capabilities !== null && $capabilities->sync),
            ],
        ]);
    }

    /**
     * The password the provider issued, shown once on request.
     *
     * A separate endpoint rather than a field on the page, so that reading
     * somebody's control panel password is an action in the audit log
     * instead of a side effect of opening a screen.
     */
    public function credentials(Service $service): RedirectResponse
    {
        $this->authorize('update', $service);

        return back()->with('credentials', [
            'username' => $service->username,
            'password' => $service->password,
        ]);
    }

    public function provision(Service $service): RedirectResponse
    {
        $this->authorize('provision', $service);

        $this->dispatcher->handle(
            OperationType::ServiceProvision,
            $service,
            new ProvisionServiceJob($service->id, $this->correlation->id()),
            $this->actor->model(),
        );

        return back()->with('status', __('provisioning.services.queued'));
    }

    public function action(ServiceActionRequest $request, Service $service): RedirectResponse
    {
        $operation = ServiceOperation::from($request->string('operation')->toString());

        $this->authorize(match ($operation) {
            ServiceOperation::Suspend, ServiceOperation::Unsuspend => 'suspend',
            ServiceOperation::Terminate => 'terminate',
            default => 'update',
        }, $service);

        $this->dispatcher->handle(
            $this->operationTypeFor($operation),
            $service,
            new RunServiceAction($service->id, $operation, $request->input('reason'), $this->correlation->id()),
            $this->actor->model(),
        );

        return back()->with('status', __('provisioning.services.queued'));
    }

    /**
     * An operator declaring where a service actually is.
     *
     * Deliberately narrow: `active` and `provisioning` are not offered,
     * because saying a service works does not make an account exist. That
     * is what running the operation is for.
     */
    public function transition(
        Request $request,
        Service $service,
        TransitionService $transitions,
    ): RedirectResponse {
        $this->authorize('update', $service);

        $target = ServiceStatus::from((string) $request->input('status'));

        $transitions->handle($service, $target, $this->actor->model(), $request->input('reason'));

        return back()->with('status', __('provisioning.services.saved'));
    }

    /**
     * Why this service is on the node it is on.
     *
     * The last decision rather than all of them: a screen answering "why here"
     * is answering about where it is now, and the history is a report rather
     * than a panel. Null when the service was placed before the platform
     * recorded its reasons, and the screen says so rather than drawing an empty
     * table.
     *
     * @return array<string, mixed>|null
     */
    private function placement(Service $service): ?array
    {
        $placement = ServicePlacement::query()
            ->where('service_id', $service->id)
            ->latest('decided_at')
            ->first();

        if (! $placement instanceof ServicePlacement) {
            return null;
        }

        return [
            'strategy' => $placement->strategy->value,
            'strategyLabel' => (string) __($placement->strategy->labelKey()),
            'server' => $placement->server_name,
            'score' => $placement->score,
            'candidates' => $placement->candidates,
            'decidedAt' => $placement->decided_at->toIso8601String(),
            'factors' => array_map(
                static fn (array $reading): array => [
                    'factor' => $reading['factor']->value,
                    'label' => (string) __($reading['factor']->labelKey()),
                    'measured' => $reading['factor']->isMeasured(),
                    'score' => $reading['score'],
                    'weight' => $reading['weight'],
                    'measure' => $reading['measure'],
                    'assumed' => $reading['assumed'],
                ],
                $placement->readings(),
            ),
        ];
    }

    /**
     * The Operations Center's name for a service action.
     *
     * Mapped rather than reused: `ServiceOperation` is what an adapter is
     * asked to do, `OperationType` is what an operator sees on a screen,
     * and collapsing the two would tie a provider contract to a filter.
     */
    private function operationTypeFor(ServiceOperation $operation): OperationType
    {
        return match ($operation) {
            ServiceOperation::Suspend => OperationType::ServiceSuspend,
            ServiceOperation::Unsuspend => OperationType::ServiceUnsuspend,
            ServiceOperation::Terminate => OperationType::ServiceTerminate,
            ServiceOperation::ChangePackage => OperationType::ServiceChangePackage,
            ServiceOperation::Sync, ServiceOperation::TestConnection => OperationType::ServiceSync,
            ServiceOperation::Create => OperationType::ServiceProvision,
        };
    }

    /**
     * One row, plus everything the `+` opens.
     *
     * The detail is sent with the list rather than fetched on expand: it
     * is eight columns already loaded, and a request per row would turn a
     * glance into twenty-five round trips.
     *
     * @return array<string, mixed>
     */
    private function row(Service $service): array
    {
        $card = $service->customer?->defaultPaymentMethod;

        return [
            'id' => $service->id,
            'name' => $service->name,
            'status' => $service->status->value,
            'statusLabel' => (string) __($service->status->labelKey()),
            'customer' => $service->customer?->displayName(),
            'customerId' => $service->customer_id,
            'domain' => $service->domain,
            'server' => $service->server?->name,
            'recurring' => $service->recurring->format(app()->getLocale()),
            'billingCycle' => $service->billing_cycle?->value,
            'billingCycleLabel' => $service->billing_cycle === null
                ? null
                : (string) __($service->billing_cycle->labelKey()),
            'nextDueOn' => $service->next_due_on?->toDateString(),
            'createdAt' => $service->created_at?->toIso8601String(),
            'detail' => [
                'orderNumber' => $service->order?->number,
                'orderId' => $service->order_id,
                'server' => $service->server?->name,
                // The card on the customer's file. There is no payment
                // method on a service in this platform, and inventing a
                // column for one would leave two answers to one question.
                'paymentMethod' => $card === null
                    ? null
                    : trim($card->gateway.' '.($card->brand ?? '').' '.($card->last_four === null ? '' : '•••• '.$card->last_four)),
                'registeredOn' => ($service->starts_on ?? $service->created_at)?->toDateString(),
                'dedicatedIp' => $this->dedicatedIp($service),
                'username' => $service->username,
                'promotionCode' => $service->order?->promotion_code,
                'product' => $service->product?->name,
                'productType' => $service->product === null
                    ? null
                    : (string) __($service->product->type->labelKey()),
            ],
        ];
    }

    /**
     * Whatever the module recorded as this service's own address.
     *
     * Read from the configuration the provisioning module wrote rather
     * than from a column, because not every kind of service has one and a
     * column that is null for eight products in nine is a column that
     * teaches operators to ignore it.
     */
    private function dedicatedIp(Service $service): ?string
    {
        $value = $service->configuration['dedicated_ip'] ?? $service->configuration['ip'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
