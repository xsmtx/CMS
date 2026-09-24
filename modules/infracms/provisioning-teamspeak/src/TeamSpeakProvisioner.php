<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningTeamSpeak;

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
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * TeamSpeak, over the WebQuery HTTP API.
 *
 * **WebQuery, not ServerQuery.** The old interface is a raw telnet protocol with
 * its own escaping rules, and speaking it from PHP means holding a socket open
 * across a queued job. WebQuery is the same commands over HTTP with an
 * `x-api-key` header, which is the only version of this worth shipping.
 *
 * **A virtual server has a port, and the port is the product.** Customers
 * connect to `host:port`, so the port TeamSpeak assigns is recorded in the
 * metadata — without it the customer has a server they cannot find.
 *
 * **Stopping is not deleting.** `serverstop` leaves the virtual server, its
 * channels and its permissions in place; `serverdelete` does not, and it cannot
 * be undone. Suspension stops; termination stops and then deletes, in that
 * order, because TeamSpeak refuses to delete a running server.
 *
 * It has never talked to a TeamSpeak instance. Written against the published
 * documentation and tested against faked HTTP.
 */
final readonly class TeamSpeakProvisioner implements ProvisioningModule
{
    public function __construct(
        private int $defaultSlots = 10,
        private int $timeout = 20,
    ) {}

    public function key(): string
    {
        return 'teamspeak';
    }

    public function capabilities(): ModuleCapabilities
    {
        return new ModuleCapabilities(
            create: true,
            suspend: true,
            unsuspend: true,
            terminate: true,
            changePackage: true,
            sync: true,
            testConnection: true,
            issuesCredentials: true,
            needsServer: true,
            productTypes: ['teamspeak'],
        );
    }

    public function testConnection(ServerConnection $server): ConnectionResult
    {
        $started = microtime(true);
        $body = $this->command($server, 'version', []);

        if ($body === null) {
            return ConnectionResult::failed('The TeamSpeak instance did not answer.');
        }

        if ($this->refused($body)) {
            return ConnectionResult::failed($this->reason($body));
        }

        return ConnectionResult::ok(
            version: is_string($body['body'][0]['version'] ?? null) ? $body['body'][0]['version'] : null,
            durationMs: (int) ((microtime(true) - $started) * 1000),
        );
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $server = $request->server;

        if ($server === null) {
            return ProvisioningResult::failed('TeamSpeak needs an instance.');
        }

        $body = $this->command($server, 'servercreate', [
            'virtualserver_name' => $request->domain ?? $request->serviceId,
            'virtualserver_maxclients' => $this->slotsFor($request->package),
        ]);

        if ($body === null) {
            return ProvisioningResult::failed('The TeamSpeak instance did not answer.');
        }

        if ($this->refused($body)) {
            return ProvisioningResult::failed($this->reason($body));
        }

        /** @var array<string, mixed> $created */
        $created = $body['body'][0] ?? [];

        return ProvisioningResult::succeeded(
            externalId: (string) ($created['sid'] ?? ''),
            username: 'serveradmin',
            // The privilege key, handed out once and never retrievable.
            password: is_string($created['token'] ?? null) ? $created['token'] : null,
            metadata: array_filter([
                // Without the port the customer has a server they cannot find.
                'port' => $created['virtualserver_port'] ?? null,
                'host' => $server->hostname,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        return $this->simple($service, 'serverstop');
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return $this->simple($service, 'serverstart');
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        // Stopped first: TeamSpeak refuses to delete a running virtual server,
        // and a terminate that failed for that reason would retry forever.
        $this->simple($service, 'serverstop');

        return $this->simple($service, 'serverdelete');
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        $server = $service->server;
        $sid = $service->externalId;

        if ($server === null || $sid === null) {
            return ProvisioningResult::failed('TeamSpeak needs an instance and a server id.');
        }

        $body = $this->command($server, 'serveredit', [
            'sid' => $sid,
            'virtualserver_maxclients' => $this->slotsFor($change->toPackage),
        ]);

        if ($body === null || $this->refused($body)) {
            return ProvisioningResult::failed($body === null
                ? 'The TeamSpeak instance did not answer.'
                : $this->reason($body));
        }

        return ProvisioningResult::succeeded(externalId: $sid);
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $server = $service->server;
        $sid = $service->externalId;

        if ($server === null || $sid === null) {
            return SyncResult::unreachable('TeamSpeak needs an instance and a server id.');
        }

        $body = $this->command($server, 'serverinfo', ['sid' => $sid]);

        if ($body === null) {
            return SyncResult::unreachable('The TeamSpeak instance did not answer.');
        }

        if ($this->refused($body)) {
            return SyncResult::unreachable($this->reason($body));
        }

        /** @var array<string, mixed> $info */
        $info = $body['body'][0] ?? [];

        return new SyncResult(
            reachable: true,
            remoteStatus: (string) ($info['virtualserver_status'] ?? '') === 'online'
                ? ServiceStatus::Active
                : ServiceStatus::Suspended,
            usage: array_filter([
                'clients' => $info['virtualserver_clientsonline'] ?? null,
                'slots' => $info['virtualserver_maxclients'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function simple(ServiceReference $service, string $command): ProvisioningResult
    {
        $server = $service->server;
        $sid = $service->externalId;

        if ($server === null || $sid === null) {
            return ProvisioningResult::failed('TeamSpeak needs an instance and a server id.');
        }

        $body = $this->command($server, $command, ['sid' => $sid]);

        if ($body === null) {
            return ProvisioningResult::failed('The TeamSpeak instance did not answer.');
        }

        if (! $this->refused($body)) {
            return ProvisioningResult::succeeded(externalId: $sid);
        }

        $reason = $this->reason($body);

        // Already stopped, or already gone, is the state the caller wanted.
        if (str_contains(strtolower($reason), 'invalid server id')
            || str_contains(strtolower($reason), 'already')) {
            return ProvisioningResult::alreadyDone($sid, $reason);
        }

        return ProvisioningResult::failed($reason);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|null
     */
    private function command(ServerConnection $server, string $command, array $parameters): ?array
    {
        try {
            $response = Http::withHeaders(['x-api-key' => $server->secret])
                ->timeout($this->timeout)
                ->retry(2, 250, throw: false)
                ->acceptJson()
                ->get($server->baseUrl().'/1/'.$command, $parameters);
        } catch (Throwable) {
            return null;
        }

        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function refused(array $body): bool
    {
        // TeamSpeak reports its own outcome in a status block, and id 0 is the
        // only success.
        return (string) ($body['status']['code'] ?? '1') !== '0';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reason(array $body): string
    {
        $message = $body['status']['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'TeamSpeak refused the request.';
    }

    /**
     * A TeamSpeak product is sold by slots, so the package usually is a number.
     */
    private function slotsFor(string $package): int
    {
        return ctype_digit($package) && (int) $package > 0 ? (int) $package : $this->defaultSlots;
    }
}
