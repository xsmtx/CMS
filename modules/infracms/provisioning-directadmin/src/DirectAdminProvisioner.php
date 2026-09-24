<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningDirectAdmin;

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
 * DirectAdmin, over its legacy CMD_API endpoints.
 *
 * **Its answers are URL-encoded, not JSON.** `CMD_API_*` replies with a
 * querystring — `error=1&text=...&details=...` — so every response is parsed
 * with `parse_str` and `error` is the field that decides. A module that looked
 * for an HTTP status would call every failure a success: DirectAdmin answers
 * 200 and says no in the body.
 *
 * **A username is at most ten characters, lower case, starting with a letter.**
 * DirectAdmin enforces it and rejects anything else, so the name is derived here
 * rather than discovered at the till.
 *
 * **Deleting is `CMD_API_SELECT_USERS` with `confirmed`**, and it is the one
 * call that cannot be undone. `alreadyDone` is returned when the account is
 * already gone, because that is the state the caller was trying to reach and it
 * is what makes a retry safe (ADR 0026).
 *
 * It has never talked to a DirectAdmin server. Written against the published
 * documentation and tested against faked HTTP, which proves the code and not the
 * integration.
 */
final readonly class DirectAdminProvisioner implements ProvisioningModule
{
    public function __construct(
        private bool $dedicatedIp = false,
        private bool $notifyCustomer = false,
        private int $timeout = 30,
    ) {}

    public function key(): string
    {
        return 'directadmin';
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
            productTypes: ['shared_hosting', 'reseller'],
        );
    }

    public function testConnection(ServerConnection $server): ConnectionResult
    {
        $started = microtime(true);

        try {
            $response = $this->client($server)->get($server->baseUrl().'/CMD_API_SHOW_USERS');
        } catch (Throwable $exception) {
            return ConnectionResult::failed($exception->getMessage());
        }

        $payload = $this->payload($response->body());

        if ($this->failed($payload)) {
            return ConnectionResult::failed($this->reason($payload));
        }

        return ConnectionResult::ok(
            durationMs: (int) ((microtime(true) - $started) * 1000),
        );
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $server = $request->server;

        if ($server === null) {
            return ProvisioningResult::failed('DirectAdmin needs a server.');
        }

        $username = $this->usernameFor($request->username ?? $request->domain ?? $request->serviceId);
        $password = $this->password();

        try {
            $response = $this->client($server)->asForm()->post($server->baseUrl().'/CMD_API_ACCOUNT_USER', [
                'action' => 'create',
                'add' => 'Submit',
                'username' => $username,
                'email' => $request->email ?? '',
                'passwd' => $password,
                'passwd2' => $password,
                'domain' => $request->domain ?? '',
                'package' => $request->package,
                'ip' => $this->dedicatedIp ? 'assign' : 'shared',
                'notify' => $this->notifyCustomer ? 'yes' : 'no',
            ]);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        $payload = $this->payload($response->body());

        if (! $this->failed($payload)) {
            return ProvisioningResult::succeeded(
                externalId: $username,
                username: $username,
                password: $password,
                metadata: ['domain' => $request->domain],
            );
        }

        $reason = $this->reason($payload);

        // Already there is the state the caller wanted, so a retry after a
        // timeout reports success rather than a permanent failure.
        if (str_contains(strtolower($reason), 'already exists')) {
            return ProvisioningResult::alreadyDone($username, $reason);
        }

        return ProvisioningResult::failed($reason);
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        return $this->select($service, 'suspend');
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return $this->select($service, 'unsuspend');
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        return $this->select($service, 'delete');
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        $server = $service->server;

        if ($server === null) {
            return ProvisioningResult::failed('DirectAdmin needs a server.');
        }

        try {
            $response = $this->client($server)->asForm()->post($server->baseUrl().'/CMD_API_MODIFY_USER', [
                'action' => 'package',
                'user' => $service->username ?? $service->externalId ?? '',
                'package' => $change->toPackage,
            ]);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        $payload = $this->payload($response->body());

        return $this->failed($payload)
            ? ProvisioningResult::failed($this->reason($payload))
            : ProvisioningResult::succeeded(externalId: $service->externalId);
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $server = $service->server;
        $user = $service->username ?? $service->externalId;

        if ($server === null || $user === null) {
            return SyncResult::unreachable('DirectAdmin needs a server and a username.');
        }

        try {
            $response = $this->client($server)->get($server->baseUrl().'/CMD_API_SHOW_USER_CONFIG', [
                'user' => $user,
            ]);
        } catch (Throwable $exception) {
            return SyncResult::unreachable($exception->getMessage());
        }

        $payload = $this->payload($response->body());

        if ($this->failed($payload)) {
            return SyncResult::unreachable($this->reason($payload));
        }

        return new SyncResult(
            reachable: true,
            // DirectAdmin says `suspended=yes|no`; anything else is running.
            remoteStatus: ($payload['suspended'] ?? 'no') === 'yes'
                ? ServiceStatus::Suspended
                : ServiceStatus::Active,
            usage: array_filter([
                'disk_mb' => $payload['quota'] ?? null,
                'bandwidth_mb' => $payload['bandwidth'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function select(ServiceReference $service, string $action): ProvisioningResult
    {
        $server = $service->server;
        $user = $service->username ?? $service->externalId;

        if ($server === null || $user === null) {
            return ProvisioningResult::failed('DirectAdmin needs a server and a username.');
        }

        $fields = [
            'location' => 'CMD_SELECT_USERS',
            'select0' => $user,
            $action === 'delete' ? 'delete' : 'dosuspend' => 'Confirm',
        ];

        if ($action === 'unsuspend') {
            $fields['suspend'] = 'Unsuspend';
        } elseif ($action === 'suspend') {
            $fields['suspend'] = 'Suspend';
        } else {
            $fields['confirmed'] = 'Confirm';
        }

        try {
            $response = $this->client($server)
                ->asForm()
                ->post($server->baseUrl().'/CMD_API_SELECT_USERS', $fields);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        $payload = $this->payload($response->body());

        if (! $this->failed($payload)) {
            return ProvisioningResult::succeeded(externalId: $user);
        }

        $reason = $this->reason($payload);

        // Gone already is done already.
        if ($action === 'delete' && str_contains(strtolower($reason), 'unable to find')) {
            return ProvisioningResult::alreadyDone($user, $reason);
        }

        return ProvisioningResult::failed($reason);
    }

    private function client(ServerConnection $server): PendingRequest
    {
        return Http::withBasicAuth($server->username, $server->secret)
            ->timeout($this->timeout)
            ->retry(2, 250, throw: false)
            ->asForm();
    }

    /**
     * DirectAdmin answers with a querystring, not JSON.
     *
     * @return array<string, mixed>
     */
    private function payload(string $body): array
    {
        parse_str($body, $fields);

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function failed(array $payload): bool
    {
        // 200 with `error=1` is how DirectAdmin says no. Reading the HTTP
        // status instead would call every refusal a success.
        return (string) ($payload['error'] ?? '0') === '1';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reason(array $payload): string
    {
        $text = trim((string) ($payload['text'] ?? ''));
        $details = trim(strip_tags((string) ($payload['details'] ?? '')));

        $reason = trim($text.' '.$details);

        return $reason === '' ? 'DirectAdmin refused the request.' : $reason;
    }

    /**
     * At most ten characters, lower case, beginning with a letter.
     *
     * DirectAdmin enforces this and refuses anything else, so it is derived
     * here rather than discovered when a customer's order fails.
     */
    private function usernameFor(string $seed): string
    {
        $name = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $seed));

        if ($name === '' || ! ctype_alpha($name[0])) {
            $name = 'u'.$name;
        }

        return substr($name, 0, 10);
    }

    private function password(): string
    {
        // Mixed case, digits and a symbol DirectAdmin accepts, generated here
        // and returned once. Nothing stores it in this module.
        return 'Da'.bin2hex(random_bytes(6)).'!'.random_int(10, 99);
    }
}
