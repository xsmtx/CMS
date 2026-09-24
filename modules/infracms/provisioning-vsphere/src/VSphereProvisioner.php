<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningVSphere;

use App\Domain\Provisioning\ConnectionResult;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Provisioning\ModuleCapabilities;
use App\Domain\Provisioning\PackageChange;
use App\Domain\Provisioning\ProvisioningRequest;
use App\Domain\Provisioning\ProvisioningResult;
use App\Domain\Provisioning\ServerConnection;
use App\Domain\Provisioning\ServiceReference;
use App\Domain\Provisioning\ServiceStatus;
use App\Domain\Provisioning\SyncResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * vSphere, over the Automation API.
 *
 * **Every call needs a session, and a session is obtained by posting nothing.**
 * `POST /api/session` with basic auth returns a token that then goes in
 * `vmware-api-session-id`. There is no long-lived key, so the session is created
 * per operation and not cached: a token cached across a queued job is a token
 * that has expired by the time the job runs, and the failure looks like bad
 * credentials.
 *
 * **Nothing is addressed by name.** vSphere identifies a VM, a folder, a
 * datastore and a resource pool by opaque ids — `vm-1042`, `datastore-17` — so
 * every name an operator typed has to be looked up first. That is why creating
 * makes several calls before it makes the one that matters.
 *
 * **A VM must be powered off before it is deleted**, and vSphere returns 400
 * rather than doing it for you. Terminate powers off first, and treats "already
 * powered off" as success rather than as a failure to retry forever.
 *
 * It has never talked to a vCenter. Written against the published documentation
 * and tested against faked HTTP.
 */
final readonly class VSphereProvisioner implements ProvisioningModule
{
    public function __construct(
        private string $template,
        private string $folder = '',
        private string $datastore = '',
        private string $resourcePool = '',
        private int $timeout = 60,
    ) {}

    public function key(): string
    {
        return 'vsphere';
    }

    public function capabilities(): ModuleCapabilities
    {
        return new ModuleCapabilities(
            create: true,
            suspend: true,
            unsuspend: true,
            terminate: true,
            changePackage: false,
            sync: true,
            testConnection: true,
            issuesCredentials: false,
            needsServer: true,
            productTypes: ['vps', 'cloud'],
        );
    }

    public function testConnection(ServerConnection $server): ConnectionResult
    {
        $started = microtime(true);
        $session = $this->session($server);

        if ($session === null) {
            return ConnectionResult::failed('vCenter would not open a session.');
        }

        return ConnectionResult::ok(durationMs: (int) ((microtime(true) - $started) * 1000));
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $server = $request->server;

        if ($server === null) {
            return ProvisioningResult::failed('vSphere needs a vCenter.');
        }

        $session = $this->session($server);

        if ($session === null) {
            return ProvisioningResult::failed('vCenter would not open a session.');
        }

        // Nothing is addressed by name, so the template's id comes first.
        $templateId = $this->idOf($server, $session, '/api/vcenter/vm', ['names' => $this->template], 'vm');

        if ($templateId === null) {
            return ProvisioningResult::failed('vCenter has no VM called '.$this->template.'.');
        }

        $placement = array_filter([
            'folder' => $this->lookup($server, $session, '/api/vcenter/folder', $this->folder, 'folder'),
            'datastore' => $this->lookup($server, $session, '/api/vcenter/datastore', $this->datastore, 'datastore'),
            'resource_pool' => $this->lookup($server, $session, '/api/vcenter/resource-pool', $this->resourcePool, 'resource_pool'),
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $response = $this->client($server, $session)->post(
                $server->baseUrl().'/api/vcenter/vm-template/library-items/'.$templateId.'/check-outs',
                array_filter([
                    'name' => $this->nameFor($request),
                    'placement' => $placement === [] ? null : $placement,
                    'powered_on' => true,
                ], static fn (mixed $value): bool => $value !== null),
            );
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        if ($response->failed()) {
            return ProvisioningResult::failed($this->reason($response->json() ?? []));
        }

        $id = $response->json();

        return ProvisioningResult::succeeded(
            externalId: is_string($id) ? $id : (string) ($response->json('value') ?? ''),
            metadata: ['template' => $this->template],
        );
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        return $this->power($service, 'stop');
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return $this->power($service, 'start');
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        $server = $service->server;
        $id = $service->externalId;

        if ($server === null || $id === null) {
            return ProvisioningResult::failed('vSphere needs a vCenter and a VM id.');
        }

        // vCenter answers 400 rather than powering it off for you.
        $this->power($service, 'stop');

        $session = $this->session($server);

        if ($session === null) {
            return ProvisioningResult::failed('vCenter would not open a session.');
        }

        try {
            $response = $this->client($server, $session)->delete($server->baseUrl().'/api/vcenter/vm/'.$id);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        if ($response->status() === 404) {
            return ProvisioningResult::alreadyDone($id, 'The VM was already gone.');
        }

        return $response->failed()
            ? ProvisioningResult::failed($this->reason($response->json() ?? []))
            : ProvisioningResult::succeeded(externalId: $id);
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        return ProvisioningResult::failed(
            'Resizing a vSphere VM needs it powered off and, for the disk, is one way only. Do it by hand.'
        );
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $server = $service->server;
        $id = $service->externalId;

        if ($server === null || $id === null) {
            return SyncResult::unreachable('vSphere needs a vCenter and a VM id.');
        }

        $session = $this->session($server);

        if ($session === null) {
            return SyncResult::unreachable('vCenter would not open a session.');
        }

        try {
            $response = $this->client($server, $session)
                ->get($server->baseUrl().'/api/vcenter/vm/'.$id.'/power');
        } catch (Throwable $exception) {
            return SyncResult::unreachable($exception->getMessage());
        }

        if ($response->status() === 404) {
            return new SyncResult(reachable: true, remoteStatus: ServiceStatus::Terminated);
        }

        if ($response->failed()) {
            return SyncResult::unreachable($this->reason($response->json() ?? []));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return new SyncResult(
            reachable: true,
            remoteStatus: match ((string) ($body['state'] ?? '')) {
                'POWERED_ON' => ServiceStatus::Active,
                'POWERED_OFF', 'SUSPENDED' => ServiceStatus::Suspended,
                default => null,
            },
        );
    }

    private function power(ServiceReference $service, string $action): ProvisioningResult
    {
        $server = $service->server;
        $id = $service->externalId;

        if ($server === null || $id === null) {
            return ProvisioningResult::failed('vSphere needs a vCenter and a VM id.');
        }

        $session = $this->session($server);

        if ($session === null) {
            return ProvisioningResult::failed('vCenter would not open a session.');
        }

        try {
            $response = $this->client($server, $session)
                ->post($server->baseUrl().'/api/vcenter/vm/'.$id.'/power?action='.$action);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        // Already in that state is the state the caller wanted, and vCenter
        // says so with a specific error rather than a success.
        if ($response->failed() && str_contains(strtolower($response->body()), 'already')) {
            return ProvisioningResult::alreadyDone($id, 'The VM was already there.');
        }

        return $response->failed()
            ? ProvisioningResult::failed($this->reason($response->json() ?? []))
            : ProvisioningResult::succeeded(externalId: $id);
    }

    /**
     * A session token, created per operation.
     *
     * Not cached: there is no long-lived key, and a token cached across a queued
     * job has expired by the time the job runs — a failure that looks exactly
     * like bad credentials.
     */
    private function session(ServerConnection $server): ?string
    {
        try {
            $response = Http::withBasicAuth($server->username, $server->secret)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($server->baseUrl().'/api/session');
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $token = $response->json();

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $value = $response->json('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function idOf(ServerConnection $server, string $session, string $path, array $query, string $field): ?string
    {
        try {
            $response = $this->client($server, $session)->get($server->baseUrl().$path, $query);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) ($response->json() ?? []);

        $first = $rows[0] ?? null;

        return is_array($first) && is_string($first[$field] ?? null) ? $first[$field] : null;
    }

    private function lookup(ServerConnection $server, string $session, string $path, string $name, string $field): ?string
    {
        return $name === '' ? null : $this->idOf($server, $session, $path, ['names' => $name], $field);
    }

    private function client(ServerConnection $server, string $session): PendingRequest
    {
        return Http::withHeaders(['vmware-api-session-id' => $session])
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false)
            ->acceptJson();
    }

    private function nameFor(ProvisioningRequest $request): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9.-]/', '-', $request->domain ?? $request->serviceId);

        return substr(trim($name, '-'), 0, 80);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function reason(?array $body): string
    {
        $messages = $body['messages'] ?? null;

        if (is_array($messages) && is_string($messages[0]['default_message'] ?? null)) {
            return (string) $messages[0]['default_message'];
        }

        return 'vCenter refused the request.';
    }
}
