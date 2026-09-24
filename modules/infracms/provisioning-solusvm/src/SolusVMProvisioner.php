<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningSolusVM;

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
 * SolusVM, over its admin API.
 *
 * **Everything is one endpoint and a form field called `action`.** There are no
 * paths and no verbs: `/api/admin/command.php` with `action=vserver-create`,
 * `action=vserver-suspend`, and so on. That is why this class is mostly one
 * private method.
 *
 * **It answers with a querystring and says no in a 200.** `status=error` and
 * `statusmsg=...`, so the HTTP status decides nothing.
 *
 * **The id and the credentials arrive together, once.** `vserver-create` returns
 * the vserverid, the root password and the assigned IP in the same response, and
 * there is no way to ask for the password again afterwards. If this call
 * succeeds and the caller then fails to record it, the customer has a machine
 * nobody can log into — which is why the external id is written the moment the
 * provider returns it (ADR 0026).
 *
 * It has never talked to a SolusVM master. Written against the published
 * documentation and tested against faked HTTP.
 */
final readonly class SolusVMProvisioner implements ProvisioningModule
{
    public function __construct(
        private string $virtualization,
        private string $nodeGroup,
        private string $template,
        private int $timeout = 60,
    ) {}

    public function key(): string
    {
        return 'solusvm';
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
            productTypes: ['vps'],
        );
    }

    public function testConnection(ServerConnection $server): ConnectionResult
    {
        $started = microtime(true);
        $payload = $this->command($server, 'node-idlist', []);

        if ($payload === null) {
            return ConnectionResult::failed('The SolusVM master did not answer.');
        }

        return $this->refused($payload)
            ? ConnectionResult::failed($this->reason($payload))
            : ConnectionResult::ok(durationMs: (int) ((microtime(true) - $started) * 1000));
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $server = $request->server;

        if ($server === null) {
            return ProvisioningResult::failed('SolusVM needs a master.');
        }

        $payload = $this->command($server, 'vserver-create', array_filter([
            'type' => $this->virtualization,
            'node' => $this->nodeGroup === '' ? null : $this->nodeGroup,
            'nodegroup' => $this->nodeGroup === '' ? null : $this->nodeGroup,
            'hostname' => $request->domain ?? $request->serviceId,
            'password' => $this->password(),
            'username' => $request->username ?? '',
            'plan' => $request->package,
            'template' => $this->template,
            'ips' => 1,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        if ($payload === null) {
            return ProvisioningResult::failed('The SolusVM master did not answer.');
        }

        if ($this->refused($payload)) {
            return ProvisioningResult::failed($this->reason($payload));
        }

        // The id, the password and the IP arrive together and only once.
        return ProvisioningResult::succeeded(
            externalId: (string) ($payload['vserverid'] ?? ''),
            username: 'root',
            password: is_string($payload['rootpassword'] ?? null) ? $payload['rootpassword'] : null,
            metadata: array_filter([
                'ip' => $payload['mainipaddress'] ?? null,
                'hostname' => $request->domain,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        return $this->simple($service, 'vserver-suspend');
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return $this->simple($service, 'vserver-unsuspend');
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        return $this->simple($service, 'vserver-terminate', ['deleteclient' => 'false']);
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        return $this->simple($service, 'vserver-change', ['plan' => $change->toPackage]);
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $server = $service->server;
        $id = $service->externalId;

        if ($server === null || $id === null) {
            return SyncResult::unreachable('SolusVM needs a master and a vserver id.');
        }

        $payload = $this->command($server, 'vserver-info', ['vserverid' => $id]);

        if ($payload === null) {
            return SyncResult::unreachable('The SolusVM master did not answer.');
        }

        if ($this->refused($payload)) {
            $reason = $this->reason($payload);

            // A vserver SolusVM has never heard of is a terminated one, not an
            // unreachable master.
            return str_contains(strtolower($reason), 'not found')
                ? new SyncResult(reachable: true, remoteStatus: ServiceStatus::Terminated)
                : SyncResult::unreachable($reason);
        }

        return new SyncResult(
            reachable: true,
            remoteStatus: match ((string) ($payload['state'] ?? '')) {
                'online' => ServiceStatus::Active,
                'disabled', 'suspended' => ServiceStatus::Suspended,
                default => null,
            },
            usage: array_filter([
                'ip' => $payload['mainipaddress'] ?? null,
                'bandwidth' => $payload['bandwidth'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function simple(ServiceReference $service, string $action, array $fields = []): ProvisioningResult
    {
        $server = $service->server;
        $id = $service->externalId;

        if ($server === null || $id === null) {
            return ProvisioningResult::failed('SolusVM needs a master and a vserver id.');
        }

        $payload = $this->command($server, $action, [...$fields, 'vserverid' => $id]);

        if ($payload === null) {
            return ProvisioningResult::failed('The SolusVM master did not answer.');
        }

        if (! $this->refused($payload)) {
            return ProvisioningResult::succeeded(externalId: $id);
        }

        $reason = $this->reason($payload);

        if (str_contains(strtolower($reason), 'not found')) {
            return ProvisioningResult::alreadyDone($id, $reason);
        }

        return ProvisioningResult::failed($reason);
    }

    /**
     * One endpoint, one `action` field.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>|null
     */
    private function command(ServerConnection $server, string $action, array $fields): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->retry(2, 500, throw: false)
                ->asForm()
                ->post($server->baseUrl().'/api/admin/command.php', [
                    // The master's API id and key, held as the connection's
                    // login and secret.
                    'id' => $server->username,
                    'key' => $server->secret,
                    'action' => $action,
                    ...$fields,
                ]);
        } catch (Throwable) {
            return null;
        }

        // A querystring, not JSON, and a 200 whether or not it worked.
        parse_str($response->body(), $payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function refused(array $payload): bool
    {
        return (string) ($payload['status'] ?? '') !== 'success';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reason(array $payload): string
    {
        $message = $payload['statusmsg'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'SolusVM refused the request.';
    }

    private function password(): string
    {
        return 'Sv'.bin2hex(random_bytes(8)).'!'.random_int(10, 99);
    }
}
