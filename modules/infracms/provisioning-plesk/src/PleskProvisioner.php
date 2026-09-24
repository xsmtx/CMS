<?php

declare(strict_types=1);

namespace InfraCMS\ProvisioningPlesk;

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
 * Plesk, over its REST API.
 *
 * **A subscription is not an account**, and conflating them is the mistake that
 * makes a Plesk integration behave oddly six months in. A customer is a
 * `client`; the thing they bought is a `domain` with a service plan attached.
 * Creating a subscription therefore creates or finds the client first and then
 * the domain under it, and terminating removes the domain — never the client,
 * who may still have three other subscriptions.
 *
 * **Suspension is a property, not a verb.** There is no suspend endpoint: a
 * subscription is disabled by setting `enabled` to false on it, which is why
 * suspend and unsuspend are one method here with a boolean.
 *
 * **Plesk answers 200 with an error object** on several failures, so the body is
 * checked as well as the status.
 *
 * It has never talked to a Plesk server. Written against the published
 * documentation and tested against faked HTTP.
 */
final readonly class PleskProvisioner implements ProvisioningModule
{
    public function __construct(
        private string $owner = '',
        private int $timeout = 30,
    ) {}

    public function key(): string
    {
        return 'plesk';
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
            $response = $this->client($server)->get($server->baseUrl().'/api/v2/server');
        } catch (Throwable $exception) {
            return ConnectionResult::failed($exception->getMessage());
        }

        if ($response->failed()) {
            return ConnectionResult::failed($this->reason($response->json() ?? []));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return ConnectionResult::ok(
            version: is_string($body['version'] ?? null) ? $body['version'] : null,
            durationMs: (int) ((microtime(true) - $started) * 1000),
        );
    }

    public function create(ProvisioningRequest $request): ProvisioningResult
    {
        $server = $request->server;
        $domain = $request->domain;

        if ($server === null || $domain === null || $domain === '') {
            return ProvisioningResult::failed('Plesk needs a server and a domain.');
        }

        $login = $this->loginFor($request->username ?? $domain);
        $password = $this->password();

        // The client first: a subscription belongs to somebody, and Plesk will
        // not create one under nobody.
        try {
            $this->client($server)->post($server->baseUrl().'/api/v2/clients', [
                'name' => $request->email ?? $login,
                'login' => $login,
                'password' => $password,
                'email' => $request->email ?? '',
                'owner_login' => $this->owner === '' ? null : $this->owner,
            ]);

            $response = $this->client($server)->post($server->baseUrl().'/api/v2/domains', [
                'name' => $domain,
                'hosting_type' => 'virtual',
                'owner_login' => $login,
                'plan_name' => $request->package,
            ]);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            $reason = $this->reason($body);

            if (str_contains(strtolower($reason), 'already exists')) {
                return ProvisioningResult::alreadyDone($domain, $reason);
            }

            return ProvisioningResult::failed($reason);
        }

        return ProvisioningResult::succeeded(
            // The domain id, because every later call addresses the
            // subscription and not the client.
            externalId: (string) ($body['id'] ?? $domain),
            username: $login,
            password: $password,
            metadata: ['domain' => $domain],
        );
    }

    public function suspend(ServiceReference $service, ?string $reason = null): ProvisioningResult
    {
        return $this->setEnabled($service, false);
    }

    public function unsuspend(ServiceReference $service): ProvisioningResult
    {
        return $this->setEnabled($service, true);
    }

    public function terminate(ServiceReference $service): ProvisioningResult
    {
        $server = $service->server;
        $id = $service->externalId;

        if ($server === null || $id === null) {
            return ProvisioningResult::failed('Plesk needs a server and a subscription.');
        }

        try {
            // The domain, never the client: they may still have three other
            // subscriptions on this server.
            $response = $this->client($server)->delete($server->baseUrl().'/api/v2/domains/'.$id);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        if ($response->status() === 404) {
            return ProvisioningResult::alreadyDone($id, 'The subscription was already gone.');
        }

        return $response->failed()
            ? ProvisioningResult::failed($this->reason($response->json() ?? []))
            : ProvisioningResult::succeeded(externalId: $id);
    }

    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult
    {
        $server = $service->server;
        $id = $service->externalId;

        if ($server === null || $id === null) {
            return ProvisioningResult::failed('Plesk needs a server and a subscription.');
        }

        try {
            $response = $this->client($server)->put($server->baseUrl().'/api/v2/domains/'.$id, [
                'plan_name' => $change->toPackage,
            ]);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        return $response->failed()
            ? ProvisioningResult::failed($this->reason($response->json() ?? []))
            : ProvisioningResult::succeeded(externalId: $id);
    }

    public function sync(ServiceReference $service): SyncResult
    {
        $server = $service->server;
        $id = $service->externalId;

        if ($server === null || $id === null) {
            return SyncResult::unreachable('Plesk needs a server and a subscription.');
        }

        try {
            $response = $this->client($server)->get($server->baseUrl().'/api/v2/domains/'.$id);
        } catch (Throwable $exception) {
            return SyncResult::unreachable($exception->getMessage());
        }

        if ($response->failed()) {
            return SyncResult::unreachable($this->reason($response->json() ?? []));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return new SyncResult(
            reachable: true,
            remoteStatus: ($body['enabled'] ?? true) === false
                ? ServiceStatus::Suspended
                : ServiceStatus::Active,
        );
    }

    private function setEnabled(ServiceReference $service, bool $enabled): ProvisioningResult
    {
        $server = $service->server;
        $id = $service->externalId;

        if ($server === null || $id === null) {
            return ProvisioningResult::failed('Plesk needs a server and a subscription.');
        }

        try {
            // There is no suspend endpoint. A subscription is disabled by
            // setting a property on it, which is why this is one method.
            $response = $this->client($server)->put($server->baseUrl().'/api/v2/domains/'.$id, [
                'enabled' => $enabled,
            ]);
        } catch (Throwable $exception) {
            return ProvisioningResult::failed($exception->getMessage());
        }

        return $response->failed()
            ? ProvisioningResult::failed($this->reason($response->json() ?? []))
            : ProvisioningResult::succeeded(externalId: $id);
    }

    private function client(ServerConnection $server): PendingRequest
    {
        return Http::withBasicAuth($server->username, $server->secret)
            ->timeout($this->timeout)
            ->retry(2, 250, throw: false)
            ->acceptJson();
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function reason(?array $body): string
    {
        $message = $body['message'] ?? $body['detail'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'Plesk refused the request.';
    }

    private function loginFor(string $seed): string
    {
        $login = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $seed));

        return substr($login === '' ? 'user' : $login, 0, 20);
    }

    private function password(): string
    {
        return 'Pk'.bin2hex(random_bytes(7)).'!'.random_int(10, 99);
    }
}
