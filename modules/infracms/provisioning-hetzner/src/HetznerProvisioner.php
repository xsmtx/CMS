<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningHetzner;

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
 * Hetzner Cloud servers, over the v1 API.
 *
 * **There is no server in the fleet**, and `needsServer` is false because of it.
 * A control panel is a machine an operator added and holds credentials for; a
 * public cloud is an account. The token therefore lives in this module's own
 * configuration — masked by the presenter, never leaving `ModuleContext` — and
 * `testConnection` is never called, because there is nothing to connect to
 * except the API itself.
 *
 * **A machine is ordered, not created.** The call returns immediately with an
 * id and a status of `initializing`; the machine exists minutes later. So a
 * successful call means accepted, and `sync` is what reports when it is
 * actually running and what address it got. Telling a customer their server is
 * ready on the strength of the 201 is how they are handed an IP that does not
 * answer.
 *
 * **Suspension powers the machine off and keeps it.** A stopped machine still
 * costs money at Hetzner, which is the honest thing to tell an operator:
 * suspending a customer stops their service, it does not stop the bill. Only
 * terminating does that, and only terminating destroys the disk.
 *
 * **Hetzner starts a server the moment it is created.** There is no way to ask
 * for one that is built but switched off, so a provisioning run that is
 * cancelled between creating and recording leaves a running machine somebody
 * pays for — which is exactly why the external id is written the moment the
 * provider returns it (ADR 0026).
 *
 * It has never talked to Hetzner. Written against the published documentation
 * and tested against faked HTTP, which proves the code and not the integration.
 */
final readonly class HetznerProvisioner implements ProvisioningModule
{
    public function __construct(
        private string $token,
        private string $region,
        private string $image,
        private int $timeout = 30,
    ) {}

    public function key(): string
    {
        return 'hetzner';
    }

    public function capabilities(): ModuleCapabilities
    {
        return new ModuleCapabilities(
            create: true,
            suspend: true,
            unsuspend: true,
            terminate: true,
            // Resizing needs the machine off and, for the disk, is one way
            // only. Not something to do without somebody watching.
            changePackage: false,
            sync: true,
            // Nothing to test: there is no server, only the API.
            testConnection: false,
            issuesCredentials: false,
            needsServer: false,
            productTypes: ['vps', 'cloud'],
        );
    }

    public function testConnection(ServerConnection $server): ConnectionResult
    {
        return ConnectionResult::failed('Hetzner is an account, not a server in the fleet.');
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $name = $this->nameFor($request);

        try {
            $response = $this->client()->post('https://api.hetzner.cloud/v1/servers', [
                'name' => $name,
                'location' => $this->region,
                'server_type' => $request->package,
                'image' => $this->image,
                // Hetzner starts a server the moment it is created; there is
                // no way to ask for one that is built but off.
                'start_after_create' => true,
                'labels' => ['managed-by' => 'infracms'],
            ]);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            return ProvisioningResult::failed($this->reason($body));
        }

        $id = $body['server']['id'] ?? '';

        return ProvisioningResult::succeeded(
            externalId: (string) $id,
            metadata: ['name' => $name, 'region' => $this->region],
        );
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        return $this->power($service, 'poweroff');
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return $this->power($service, 'poweron');
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        $id = $service->externalId;

        if ($id === null) {
            return ProvisioningResult::failed('Hetzner needs the machine id.');
        }

        try {
            $response = $this->client()->delete('https://api.hetzner.cloud/v1/servers/'.$id);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        // Gone already is done already, which is what makes a retry safe.
        if ($response->status() === 404) {
            return ProvisioningResult::alreadyDone($id, 'The machine was already gone.');
        }

        return $response->failed()
            ? ProvisioningResult::failed($this->reason($response->json() ?? []))
            : ProvisioningResult::succeeded(externalId: $id);
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        return ProvisioningResult::failed(
            'Resizing at Hetzner needs the machine off, and the disk grows one way only. Do it by hand.'
        );
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $id = $service->externalId;

        if ($id === null) {
            return SyncResult::unreachable('Hetzner needs the machine id.');
        }

        try {
            $response = $this->client()->get('https://api.hetzner.cloud/v1/servers/'.$id);
        } catch (Throwable $exception) {
            return SyncResult::unreachable($exception->getMessage());
        }

        if ($response->status() === 404) {
            return new SyncResult(reachable: true, remoteStatus: ServiceStatus::Terminated);
        }

        if ($response->failed()) {
            return SyncResult::unreachable($this->reason($response->json() ?? []));
        }

        /** @var array<string, mixed> $machine */
        $machine = ($response->json() ?? [])['server'] ?? [];

        return new SyncResult(
            reachable: true,
            remoteStatus: match ((string) ($machine['status'] ?? '')) {
                'running' => ServiceStatus::Active,
                'off', 'stopping' => ServiceStatus::Suspended,
                default => null,
            },
            usage: array_filter([
                'ipv4' => $machine['public_net']['ipv4']['ip'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    private function power(ServiceReference $service, string $action): ProvisioningResult
    {
        $id = $service->externalId;

        if ($id === null) {
            return ProvisioningResult::failed('Hetzner needs the machine id.');
        }

        try {
            $response = $this->client()->post('https://api.hetzner.cloud/v1/servers/'.$id.'/actions/'.$action.'', []);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        return $response->failed()
            ? ProvisioningResult::failed($this->reason($response->json() ?? []))
            : ProvisioningResult::succeeded(externalId: $id);
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->token)
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
        $message = $body['message'] ?? $body['error']['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'Hetzner refused the request.';
    }
}
