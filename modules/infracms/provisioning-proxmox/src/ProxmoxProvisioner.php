<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningProxmox;

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
 * Proxmox VE, over `/api2/json`.
 *
 * **Authentication is an API token, not a ticket.** Proxmox offers both; a
 * ticket expires in two hours and has to be renewed, which means a provisioning
 * worker that sat idle overnight fails its first job. A token in the server row
 * as `user@realm!tokenid=secret` never does.
 *
 * **Almost nothing here is synchronous.** A clone, a stop and a destroy each
 * return a **task id** — `UPID:...` — and the work happens afterwards. So a
 * successful call means *accepted*, not *done*, and `sync` is what eventually
 * reports the truth. Treating the 200 as completion is how a customer is told
 * their VM is ready while it is still copying a disk.
 *
 * **Suspend stops the VM rather than pausing it.** Proxmox's `suspend` writes
 * memory to disk and holds the resources; for a customer who has not paid, the
 * resources are the point, so this stops instead.
 *
 * It has never talked to a Proxmox server. Written against the published
 * documentation and tested against faked HTTP.
 */
final readonly class ProxmoxProvisioner implements ProvisioningModule
{
    public function __construct(
        private string $node,
        private int $templateId,
        private string $storage = 'local-lvm',
        private bool $fullClone = true,
        private string $bridge = 'vmbr0',
        private int $timeout = 60,
    ) {}

    public function key(): string
    {
        return 'proxmox';
    }

    public function capabilities(): ModuleCapabilities
    {
        return new ModuleCapabilities(
            create: true,
            suspend: true,
            unsuspend: true,
            terminate: true,
            // A VM's size is its hardware. Changing it needs a reboot and a
            // disk resize that cannot be undone, so it is not offered as an
            // automatic operation.
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

        try {
            $response = $this->client($server)->get($server->baseUrl().'/api2/json/version');
        } catch (Throwable $exception) {
            return ConnectionResult::failed($exception->getMessage());
        }

        if ($response->failed()) {
            return ConnectionResult::failed($this->reason($response->json() ?? []));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return ConnectionResult::ok(
            version: is_string($body['data']['version'] ?? null) ? $body['data']['version'] : null,
            durationMs: (int) ((microtime(true) - $started) * 1000),
        );
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $server = $request->server;

        if ($server === null) {
            return ProvisioningResult::failed('Proxmox needs a server.');
        }

        $vmid = $this->nextId($server);

        if ($vmid === null) {
            return ProvisioningResult::failed('Proxmox would not hand out a VMID.');
        }

        try {
            $response = $this->client($server)->asForm()->post(
                $server->baseUrl().'/api2/json/nodes/'.$this->node.'/qemu/'.$this->templateId.'/clone',
                array_filter([
                    'newid' => $vmid,
                    'name' => $this->nameFor($request),
                    'full' => $this->fullClone ? 1 : 0,
                    'storage' => $this->fullClone ? $this->storage : null,
                    'target' => $this->node,
                ], static fn (mixed $value): bool => $value !== null),
            );
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        if ($response->failed()) {
            return ProvisioningResult::failed($this->reason($response->json() ?? []));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        // Accepted, not finished. The clone is a task, and `sync` is what
        // eventually says the VM exists and runs.
        return ProvisioningResult::succeeded(
            externalId: (string) $vmid,
            metadata: [
                'node' => $this->node,
                'task' => (string) ($body['data'] ?? ''),
                'bridge' => $this->bridge,
            ],
        );
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        // Stopped, not suspended: Proxmox's own suspend holds the memory and
        // the resources, and the resources are the point.
        return $this->status($service, 'stop');
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return $this->status($service, 'start');
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        $server = $service->server;
        $vmid = $service->externalId;

        if ($server === null || $vmid === null) {
            return ProvisioningResult::failed('Proxmox needs a server and a VMID.');
        }

        // Stopped first: Proxmox refuses to destroy a running VM, and a
        // terminate that failed for that reason would be retried forever.
        $this->status($service, 'stop');

        try {
            $response = $this->client($server)->delete(
                $server->baseUrl().'/api2/json/nodes/'.$this->node.'/qemu/'.$vmid,
                ['purge' => 1, 'destroy-unreferenced-disks' => 1],
            );
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        if ($response->status() === 500 && str_contains($response->body(), 'does not exist')) {
            return ProvisioningResult::alreadyDone($vmid, 'The VM was already gone.');
        }

        return $response->failed()
            ? ProvisioningResult::failed($this->reason($response->json() ?? []))
            : ProvisioningResult::succeeded(externalId: $vmid);
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        // Declared unsupported in `capabilities()`; answered honestly here so
        // that a caller which asks anyway is told rather than ignored.
        return ProvisioningResult::failed(
            'Resizing a Proxmox VM needs a reboot and a disk change that cannot be undone. Do it by hand.'
        );
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $server = $service->server;
        $vmid = $service->externalId;

        if ($server === null || $vmid === null) {
            return SyncResult::unreachable('Proxmox needs a server and a VMID.');
        }

        try {
            $response = $this->client($server)->get(
                $server->baseUrl().'/api2/json/nodes/'.$this->node.'/qemu/'.$vmid.'/status/current'
            );
        } catch (Throwable $exception) {
            return SyncResult::unreachable($exception->getMessage());
        }

        if ($response->failed()) {
            return SyncResult::unreachable($this->reason($response->json() ?? []));
        }

        /** @var array<string, mixed> $data */
        $data = ($response->json() ?? [])['data'] ?? [];

        return new SyncResult(
            reachable: true,
            remoteStatus: match ((string) ($data['status'] ?? '')) {
                'running' => ServiceStatus::Active,
                'stopped' => ServiceStatus::Suspended,
                default => null,
            },
            usage: array_filter([
                'memory_bytes' => $data['mem'] ?? null,
                'disk_bytes' => $data['maxdisk'] ?? null,
                'uptime_seconds' => $data['uptime'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function status(ServiceReference $service, string $action): ProvisioningResult
    {
        $server = $service->server;
        $vmid = $service->externalId;

        if ($server === null || $vmid === null) {
            return ProvisioningResult::failed('Proxmox needs a server and a VMID.');
        }

        try {
            $response = $this->client($server)->asForm()->post(
                $server->baseUrl().'/api2/json/nodes/'.$this->node.'/qemu/'.$vmid.'/status/'.$action
            );
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        // Already in that state is the state the caller wanted.
        if ($response->failed() && str_contains(strtolower($response->body()), 'already')) {
            return ProvisioningResult::alreadyDone($vmid, 'The VM was already '.$action.'ped.');
        }

        return $response->failed()
            ? ProvisioningResult::failed($this->reason($response->json() ?? []))
            : ProvisioningResult::succeeded(externalId: $vmid);
    }

    /**
     * Ask Proxmox for the next free VMID rather than guessing one.
     *
     * Two orders placed in the same second would otherwise pick the same
     * number, and the second clone would fail with a collision an operator
     * would have to untangle by hand.
     */
    private function nextId(ServerConnection $server): ?int
    {
        try {
            $response = $this->client($server)->get($server->baseUrl().'/api2/json/cluster/nextid');
        } catch (Throwable) {
            return null;
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $id = (int) ($body['data'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function client(ServerConnection $server): PendingRequest
    {
        // `PVEAPIToken=user@realm!tokenid=secret`, held whole in the server's
        // secret. A ticket would expire while a worker was idle.
        return Http::withHeaders([
            'Authorization' => 'PVEAPIToken='.$server->username.'='.$server->secret,
        ])
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false)
            ->acceptJson();
    }

    private function nameFor(ProvisioningRequest $request): string
    {
        $name = strtolower((string) preg_replace('/[^a-z0-9-]/i', '-', $request->domain ?? $request->serviceId));

        return substr(trim($name, '-'), 0, 60);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function reason(?array $body): string
    {
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            return implode('; ', array_map(static fn (mixed $value): string => (string) $value, $errors));
        }

        $message = $body['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'Proxmox refused the request.';
    }
}
