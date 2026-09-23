<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Provisioning\Exceptions\PlacementFailed;
use App\Application\Provisioning\Exceptions\ServiceNotOperable;
use App\Domain\Crm\AddressType;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Provisioning\Events\ServiceProvisioned;
use App\Domain\Provisioning\Events\ServiceSuspended;
use App\Domain\Provisioning\Events\ServiceTerminated;
use App\Domain\Provisioning\OperationOutcome;
use App\Domain\Provisioning\PackageChange;
use App\Domain\Provisioning\ProvisioningRequest;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\ServiceOperation;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Provisioning\Models\ServerGroup;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\ModuleRegistry;
use App\Support\Correlation\CorrelationContext;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The one place an adapter is called.
 *
 * Everything else in this platform can be undone by rolling back a
 * transaction. An account created on somebody's control panel cannot, and
 * that single fact shapes this class:
 *
 * - **The remote call happens outside any transaction.** Holding one open
 *   across a call to a machine that might take thirty seconds is how a
 *   database runs out of connections during an incident.
 *
 * - **The external id is written the moment it arrives**, before the status
 *   changes, before the event is recorded, before anything. An id that was
 *   not stored is an account nobody can find again and nobody can bill for.
 *
 * - **`AlreadyDone` is a success.** A job that timed out after the provider
 *   had already created the account must be able to run again and reach the
 *   same place. An adapter that reports it as an error turns every retry
 *   into a permanent failure.
 *
 * - **Failure is a state.** A service that could not be set up lands in
 *   `failed` with a sanitised reason, in a queue an operator works through.
 *   It does not throw into a log nobody reads, and it does not sit in
 *   `provisioning` forever.
 */
final readonly class RunServiceOperation
{
    public function __construct(
        private ModuleRegistry $modules,
        private PlaceService $placement,
        private TransitionService $transitions,
        private RecordServiceEvent $events,
        private CorrelationContext $correlation,
        private CurrentActor $actor,
    ) {}

    /**
     * Set the service up.
     */
    public function provision(Service $service, ?Model $actor = null): ProvisioningResult
    {
        $actor ??= $this->actor->model();

        if (! $service->status->canProvision()) {
            throw ServiceNotOperable::wrongStatus($service->status->value);
        }

        $module = $this->moduleFor($service, ServiceOperation::Create);

        try {
            $this->place($service, $module);
        } catch (PlacementFailed $exception) {
            return $this->fail($service, ServiceOperation::Create, $exception->getMessage(), $actor);
        }

        $this->transitions->handle($service, ServiceStatus::Provisioning, $actor);

        $request = new ProvisioningRequest(
            serviceId: $service->id,
            server: $service->server?->connection(),
            package: (string) ($service->package ?? ''),
            domain: $service->domain,
            username: $service->username,
            email: $service->customer?->primaryContact?->email,
            options: $this->optionsFor($service),
            correlationId: $this->correlation->id(),
        );

        $result = $this->call(
            $service,
            ServiceOperation::Create,
            static fn (): ProvisioningResult => $module->create($request),
            $actor,
        );

        if (! $result->isSuccessful()) {
            return $result;
        }

        // Written first, and separately: if anything below throws, the
        // platform still knows what the provider called this account.
        $service->forceFill(array_filter([
            'external_id' => $result->externalId ?? $service->external_id,
            'username' => $result->username ?? $service->username,
            'password' => $result->password ?? $service->password,
        ], static fn (mixed $value): bool => $value !== null))->save();

        $this->transitions->handle($service, ServiceStatus::Active, $actor);

        // The welcome message Phase 6 ended with and had nothing to send.
        event(new ServiceProvisioned(
            $service->id,
            $service->organization_id,
            $this->correlation->id(),
        ));

        return $result;
    }

    public function suspend(Service $service, ?string $reason = null, ?Model $actor = null): ProvisioningResult
    {
        $actor ??= $this->actor->model();
        $module = $this->moduleFor($service, ServiceOperation::Suspend);

        $result = $this->call(
            $service,
            ServiceOperation::Suspend,
            static fn (): ProvisioningResult => $module->suspend($service->reference(), $reason),
            $actor,
        );

        if ($result->isSuccessful()) {
            $this->transitions->handle($service, ServiceStatus::Suspended, $actor, $reason);

            event(new ServiceSuspended(
                $service->id,
                $service->organization_id,
                $reason,
                $this->correlation->id(),
            ));
        }

        return $result;
    }

    public function unsuspend(Service $service, ?Model $actor = null): ProvisioningResult
    {
        $actor ??= $this->actor->model();
        $module = $this->moduleFor($service, ServiceOperation::Unsuspend);

        $result = $this->call(
            $service,
            ServiceOperation::Unsuspend,
            static fn (): ProvisioningResult => $module->unsuspend($service->reference()),
            $actor,
        );

        if ($result->isSuccessful()) {
            $this->transitions->handle($service, ServiceStatus::Active, $actor);
        }

        return $result;
    }

    /**
     * Destroys the account.
     *
     * A service that never existed remotely is terminated without a call:
     * asking a provider to delete something it was never told about
     * produces an error that means nothing.
     */
    public function terminate(Service $service, ?Model $actor = null): ProvisioningResult
    {
        $actor ??= $this->actor->model();

        if (! $service->status->existsRemotely()) {
            $this->transitions->handle($service, ServiceStatus::Terminated, $actor);
            $this->events->handle(
                $service,
                ServiceOperation::Terminate,
                OperationOutcome::AlreadyDone,
                (string) __('provisioning.events.nothing_remote'),
                actor: $actor,
            );

            return ProvisioningResult::alreadyDone();
        }

        $module = $this->moduleFor($service, ServiceOperation::Terminate);

        $result = $this->call(
            $service,
            ServiceOperation::Terminate,
            static fn (): ProvisioningResult => $module->terminate($service->reference()),
            $actor,
        );

        if ($result->isSuccessful()) {
            $this->transitions->handle($service, ServiceStatus::Terminated, $actor);

            event(new ServiceTerminated(
                $service->id,
                $service->organization_id,
                $this->correlation->id(),
            ));
        }

        return $result;
    }

    public function changePackage(Service $service, string $package, ?Model $actor = null): ProvisioningResult
    {
        $actor ??= $this->actor->model();
        $module = $this->moduleFor($service, ServiceOperation::ChangePackage);

        $change = new PackageChange(
            fromPackage: (string) ($service->package ?? ''),
            toPackage: $package,
            options: $this->optionsFor($service),
        );

        $result = $this->call(
            $service,
            ServiceOperation::ChangePackage,
            static fn (): ProvisioningResult => $module->changePackage($service->reference(), $change),
            $actor,
        );

        if ($result->isSuccessful()) {
            $service->forceFill(['package' => $package])->save();
        }

        return $result;
    }

    /**
     * Ask the provider what it thinks is true.
     *
     * Reports; does not decide. Whether a service the provider calls
     * suspended should become suspended here is a question for an operator,
     * who knows whether anybody asked for it.
     */
    public function sync(Service $service, ?Model $actor = null): ProvisioningResult
    {
        $actor ??= $this->actor->model();
        $module = $this->moduleFor($service, ServiceOperation::Sync);

        try {
            $sync = $module->sync($service->reference());
        } catch (Throwable $exception) {
            return $this->fail($service, ServiceOperation::Sync, $exception->getMessage(), $actor);
        }

        $service->forceFill(['synced_at' => CarbonImmutable::now()])->save();

        $this->events->handle(
            $service,
            ServiceOperation::Sync,
            $sync->reachable ? OperationOutcome::Succeeded : OperationOutcome::Failed,
            $sync->message,
            [
                'remote_status' => $sync->remoteStatus?->value,
                'usage' => $sync->usage,
            ],
            $actor,
        );

        return $sync->reachable
            ? ProvisioningResult::succeeded(metadata: $sync->usage)
            : ProvisioningResult::failed((string) $sync->message);
    }

    /**
     * Call the adapter, record what happened, and never let a provider's
     * exception escape as a 500.
     *
     * @param  callable(): ProvisioningResult  $operation
     */
    private function call(
        Service $service,
        ServiceOperation $name,
        callable $operation,
        ?Model $actor,
    ): ProvisioningResult {
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            return $this->fail($service, $name, $exception->getMessage(), $actor);
        }

        if (! $result->isSuccessful()) {
            return $this->fail($service, $name, (string) $result->message, $actor, $result->metadata);
        }

        $this->events->handle(
            $service,
            $name,
            $result->outcome,
            $result->message,
            $result->metadata,
            $actor,
        );

        return $result;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function fail(
        Service $service,
        ServiceOperation $name,
        string $message,
        ?Model $actor,
        array $metadata = [],
    ): ProvisioningResult {
        $this->events->handle($service, $name, OperationOutcome::Failed, $message, $metadata, $actor);

        // Only a failed setup leaves the service unusable. A suspend that
        // did not work leaves it active, which is the truth.
        if ($name === ServiceOperation::Create && $service->status->canTransitionTo(ServiceStatus::Failed)) {
            $this->transitions->handle($service, ServiceStatus::Failed, $actor, $message);
        }

        return ProvisioningResult::failed($message, $metadata);
    }

    private function moduleFor(Service $service, ServiceOperation $operation): ProvisioningModule
    {
        $key = $service->module;

        if ($key === null || $key === '') {
            throw ServiceNotOperable::noModule();
        }

        $module = $this->modules->find($key);

        if (! $module instanceof ProvisioningModule) {
            throw ServiceNotOperable::unknownModule($key);
        }

        if (! $module->capabilities()->supports($operation)) {
            throw ServiceNotOperable::unsupported($key, $operation);
        }

        return $module;
    }

    /**
     * Put the service on a node, unless it is already on one or the module
     * does not use one.
     */
    private function place(Service $service, ProvisioningModule $module): void
    {
        if (! $module->capabilities()->needsServer || $service->server_id !== null) {
            return;
        }

        $group = $service->product?->serverGroup;

        if (! $group instanceof ServerGroup) {
            throw PlacementFailed::noGroup();
        }

        $server = $this->placement->handle(
            $group,
            $service->customer?->addressFor(AddressType::Billing)?->country_code,
        );

        $service->forceFill([
            'server_id' => $server->id,
            'hostname' => $server->hostname,
        ])->save();

        $service->setRelation('server', $server);
    }

    /**
     * @return array<string, string>
     */
    private function optionsFor(Service $service): array
    {
        /** @var array<string, string> $configuration */
        $configuration = $service->configuration ?? [];

        return $configuration;
    }
}
