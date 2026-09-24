<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningDigitalOcean;

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
 * DigitalOcean Droplets, over the v2 API.
 *
 * **There is no server in the fleet**, and `needsServer` is false because of it.
 * A control panel is a machine an operator added and holds credentials for; a
 * public cloud is an account. The token therefore lives in this module's own
 * configuration — masked by the presenter, never leaving `ModuleContext` — and
 * `testConnection` is never called, because there is nothing to connect to
 * except the API itself.
 *
 * **A machine is ordered, not created.** The call returns immediately with an
 * id and a status of `new`; the machine exists minutes later. So a
 * successful call means accepted, and `sync` is what reports when it is
 * actually running and what address it got. Telling a customer their server is
 * ready on the strength of the 201 is how they are handed an IP that does not
 * answer.
 *
 * **Suspension powers the machine off and keeps it.** A stopped machine still
 * costs money at DigitalOcean, which is the honest thing to tell an operator:
 * suspending a customer stops their service, it does not stop the bill. Only
 * terminating does that, and only terminating destroys the disk.
 *
 * **The size is the package, untranslated.** What an operator sold is the slug
 * DigitalOcean knows — `s-1vcpu-1gb` — because a mapping table inside a module
 * is a mapping table somebody has to keep in step with a price list.
 *
 * It has never talked to DigitalOcean. Written against the published documentation
 * and tested against faked HTTP, which proves the code and not the integration.
 */
final readonly class DigitalOceanProvisioner implements ProvisioningModule
{
    public function __construct(
        private string $token,
        private string $region,
        private string $image,
        private int $timeout = 30,
    ) {}

    public function key(): string
    {
        return 'digitalocean';
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
        return ConnectionResult::failed('DigitalOcean is an account, not a server in the fleet.');
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $name = $this->nameFor($request);

        try {
            $response = $this->client()->post('https://api.digitalocean.com/v2/droplets', [
                'name' => $name,
                'region' => $this->region,
                // The package an operator sold is the slug DigitalOcean knows:
                // `s-1vcpu-1gb`. Nothing is translated here, because a mapping
                // table in a module is a mapping table somebody has to keep.
                'size' => $request->package,
                'image' => $this->image,
                'tags' => ['infracms', $request->serviceId],
            ]);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            return ProvisioningResult::failed($this->reason($body));
        }

        $id = $body['droplet']['id'] ?? '';

        return ProvisioningResult::succeeded(
            externalId: (string) $id,
            metadata: ['name' => $name, 'region' => $this->region],
        );
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        return $this->power($service, 'power_off');
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return $this->power($service, 'power_on');
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        $id = $service->externalId;

        if ($id === null) {
            return ProvisioningResult::failed('DigitalOcean needs the machine id.');
        }

        try {
            $response = $this->client()->delete('https://api.digitalocean.com/v2/droplets/'.$id);
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
            'Resizing at DigitalOcean needs the machine off, and the disk grows one way only. Do it by hand.'
        );
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $id = $service->externalId;

        if ($id === null) {
            return SyncResult::unreachable('DigitalOcean needs the machine id.');
        }

        try {
            $response = $this->client()->get('https://api.digitalocean.com/v2/droplets/'.$id);
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
        $machine = ($response->json() ?? [])['droplet'] ?? [];

        return new SyncResult(
            reachable: true,
            remoteStatus: match ((string) ($machine['status'] ?? '')) {
                'active' => ServiceStatus::Active,
                'off' => ServiceStatus::Suspended,
                default => null,
            },
            usage: array_filter([
                'ipv4' => $machine['networks']['v4'][0]['ip_address'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    private function power(ServiceReference $service, string $action): ProvisioningResult
    {
        $id = $service->externalId;

        if ($id === null) {
            return ProvisioningResult::failed('DigitalOcean needs the machine id.');
        }

        try {
            $response = $this->client()->post('https://api.digitalocean.com/v2/droplets/'.$id.'/actions', ['type' => $action]);
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

        return is_string($message) && $message !== '' ? $message : 'DigitalOcean refused the request.';
    }
}
