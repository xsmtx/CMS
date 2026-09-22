<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Modules;

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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * cPanel/WHM, over WHM API 1.
 *
 * Written against the API directly rather than a vendor SDK, for the same
 * reason the Stripe adapter is: the platform already has an HTTP client
 * with timeouts, bounded retries and correlation-id propagation, and the
 * handful of endpoints used here have been stable for a decade.
 *
 * Authentication is an API token, never a password. WHM's token header is
 * `Authorization: whm <user>:<token>`, and the token is the only credential
 * this adapter ever sees.
 *
 * Two behaviours are worth stating, because they are what make retrying a
 * job safe:
 *
 * - **"Account already exists" is `AlreadyDone`, not a failure.** A job
 *   that timed out after WHM had already created the account must be able
 *   to run again and arrive at the same place.
 * - **The username is derived deterministically from the domain**, so a
 *   retry asks for the same account rather than a second one with a
 *   different name.
 *
 * It has never talked to a real WHM. Its request shapes, error handling and
 * idempotency are tested against faked HTTP, which proves the code and not
 * the integration.
 */
final readonly class CpanelModule implements ProvisioningModule
{
    public function __construct(
        private int $timeout = 30,
        private int $retries = 2,
    ) {}

    public function key(): string
    {
        return 'cpanel';
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
            $response = $this->client($server)->get($this->url($server, 'version'));
        } catch (Throwable $exception) {
            return ConnectionResult::failed($exception->getMessage());
        }

        $duration = (int) ((microtime(true) - $started) * 1000);
        $payload = $this->payload($response);

        if (! $this->succeeded($payload)) {
            return ConnectionResult::failed($this->reason($payload, $response));
        }

        return ConnectionResult::ok(
            version: is_string($payload['version'] ?? null) ? $payload['version'] : null,
            durationMs: $duration,
        );
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $server = $request->server;

        if ($server === null) {
            return ProvisioningResult::failed(__('provisioning.errors.no_server_for_module'));
        }

        $username = $request->username ?? $this->usernameFor($request->domain ?? $request->serviceId);
        $password = $this->password();

        try {
            $response = $this->client($server)->get($this->url($server, 'createacct'), array_filter([
                'username' => $username,
                'domain' => $request->domain,
                'password' => $password,
                'plan' => $request->package === '' ? null : $request->package,
                'contactemail' => $request->email,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        $payload = $this->payload($response);

        if ($this->succeeded($payload)) {
            return ProvisioningResult::succeeded(
                externalId: $username,
                username: $username,
                password: $password,
                metadata: ['domain' => $request->domain],
            );
        }

        $reason = $this->reason($payload, $response);

        // The account is already there. That is the state the caller was
        // trying to reach, so a retry reports success rather than turning a
        // timeout into a permanent failure.
        if ($this->meansAlreadyExists($reason)) {
            return ProvisioningResult::alreadyDone($username, $reason);
        }

        return ProvisioningResult::failed($reason);
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        return $this->account($service, 'suspendacct', array_filter([
            'user' => $service->externalId ?? $service->username,
            'reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return $this->account($service, 'unsuspendacct', [
            'user' => $service->externalId ?? $service->username,
        ]);
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        return $this->account($service, 'removeacct', [
            'user' => $service->externalId ?? $service->username,
        ]);
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        return $this->account($service, 'changepackage', [
            'user' => $service->externalId ?? $service->username,
            'pkg' => $change->toPackage,
        ]);
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $server = $service->server;

        if ($server === null) {
            return SyncResult::unreachable((string) __('provisioning.errors.no_server_for_module'));
        }

        try {
            $response = $this->client($server)->get($this->url($server, 'accountsummary'), [
                'user' => $service->externalId ?? $service->username,
            ]);
        } catch (Throwable $exception) {
            return SyncResult::unreachable($exception->getMessage());
        }

        $payload = $this->payload($response);

        if (! $this->succeeded($payload)) {
            return SyncResult::unreachable($this->reason($payload, $response));
        }

        /** @var array<string, mixed> $account */
        $account = $payload['acct'][0] ?? [];

        return new SyncResult(
            reachable: true,
            remoteStatus: $this->statusOf($account),
            usage: array_filter([
                'disk_used' => $account['diskused'] ?? null,
                'disk_limit' => $account['disklimit'] ?? null,
                'ip' => $account['ip'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * One shape for every operation that names an existing account.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function account(ServiceReference $service, string $function, array $parameters): ProvisioningResult
    {
        $server = $service->server;

        if ($server === null) {
            return ProvisioningResult::failed((string) __('provisioning.errors.no_server_for_module'));
        }

        if (($parameters['user'] ?? null) === null) {
            // Nothing to name. Asking WHM to suspend "" would suspend
            // nothing and report success, which is worse than refusing.
            return ProvisioningResult::failed((string) __('provisioning.errors.no_external_id'));
        }

        try {
            $response = $this->client($server)->get($this->url($server, $function), $parameters);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        $payload = $this->payload($response);

        if ($this->succeeded($payload)) {
            return ProvisioningResult::succeeded(externalId: (string) $parameters['user']);
        }

        $reason = $this->reason($payload, $response);

        // Terminating something already gone, or suspending something
        // already suspended, is the state the caller wanted.
        if ($this->meansAlreadyDone($reason)) {
            return ProvisioningResult::alreadyDone((string) $parameters['user'], $reason);
        }

        return ProvisioningResult::failed($reason);
    }

    private function client(ServerConnection $server): PendingRequest
    {
        return Http::withHeaders([
            // A token, never a password. It is the only credential this
            // adapter is given and it never leaves this method.
            'Authorization' => 'whm '.$server->username.':'.$server->secret,
        ])
            ->timeout($this->timeout)
            ->retry($this->retries, 250, throw: false)
            ->acceptJson();
    }

    private function url(ServerConnection $server, string $function): string
    {
        return $server->baseUrl().'/json-api/'.$function.'?api.version=1';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Response $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return $payload;
    }

    /**
     * WHM reports success inside the body, not in the status code.
     *
     * @param  array<string, mixed>  $payload
     */
    private function succeeded(array $payload): bool
    {
        $metadata = $payload['metadata'] ?? null;

        if (is_array($metadata) && array_key_exists('result', $metadata)) {
            return (int) $metadata['result'] === 1;
        }

        // Older endpoints answer with a bare status field.
        if (array_key_exists('status', $payload)) {
            return (int) $payload['status'] === 1;
        }

        return $payload !== [] && ! array_key_exists('error', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reason(array $payload, Response $response): string
    {
        $metadata = $payload['metadata'] ?? null;

        if (is_array($metadata) && is_string($metadata['reason'] ?? null)) {
            return $metadata['reason'];
        }

        foreach (['statusmsg', 'error', 'reason'] as $key) {
            if (is_string($payload[$key] ?? null)) {
                return $payload[$key];
            }
        }

        return (string) __('provisioning.errors.provider_refused', ['status' => $response->status()]);
    }

    private function meansAlreadyExists(string $reason): bool
    {
        return Str::contains(Str::lower($reason), ['already exists', 'already a user', 'taken']);
    }

    private function meansAlreadyDone(string $reason): bool
    {
        return Str::contains(Str::lower($reason), [
            'does not exist',
            'already suspended',
            'not suspended',
            'no such user',
        ]);
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function statusOf(array $account): ServiceStatus
    {
        $suspended = $account['suspended'] ?? 0;

        return (int) $suspended === 1 ? ServiceStatus::Suspended : ServiceStatus::Active;
    }

    /**
     * cPanel usernames are eight characters, lower case, starting with a
     * letter. Derived from the domain rather than random, so a retried job
     * asks for the same account instead of creating a second one.
     */
    private function usernameFor(string $domain): string
    {
        $base = Str::lower(preg_replace('/[^a-zA-Z0-9]/', '', Str::before($domain, '.')) ?? '');

        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'u'.$base;
        }

        return Str::limit($base, 8, '');
    }

    private function password(): string
    {
        // Generated here, handed back in the result, and encrypted at rest
        // by the caller. This adapter never writes it anywhere.
        return Str::password(16, symbols: false);
    }
}
