<?php

declare(strict_types=1);

namespace InfraCMS\LoadbalancerHaproxy;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\LoadBalancerWriter;
use App\Domain\Infrastructure\Exceptions\BalancerUnreachable;
use App\Domain\Infrastructure\LoadBalancing\Backend;
use App\Domain\Infrastructure\LoadBalancing\BackendState;
use App\Domain\Infrastructure\LoadBalancing\Listener;
use App\Domain\Infrastructure\RateLimits;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HAProxy, over the Data Plane API.
 *
 * Four things about that API that only a careful read turns up, each pinned
 * by a test:
 *
 * - **A server has two states and the administrative one wins.** HAProxy
 *   reports `admin_state` (`ready`, `drain`, `maint`) and `operational_state`
 *   (`up`, `down`, `stopping`) separately. A server somebody drained is still
 *   operationally up and must not read as taking traffic; a server somebody
 *   put in maintenance is not failing a health check. The same distinction
 *   `PortState` draws between a port that is down and one that was shut.
 * - **`stopping` is draining**, and it is the state a server reaches on its
 *   own when its own configuration says so. Reading it as `up` would tell an
 *   operator to keep waiting for a count that is already coming down.
 * - **The runtime endpoint is per backend.** There is no "every server"
 *   call, so the list is one request per backend section — which is why the
 *   configured backends are read first and why the rate limit is what it is.
 * - **A drain is a `PUT` of one field.** The whole body is `{"admin_state":
 *   "drain"}`; sending the server's other fields back would overwrite
 *   whatever somebody changed in between.
 *
 * It has never talked to a real HAProxy. Every request shape and every parse
 * here is tested against faked HTTP, which proves the code and not the
 * integration.
 */
final readonly class HaproxyProvider implements LoadBalancerWriter
{
    /** How many backend sections to take. Larger than any estate maintained by hand. */
    private const int Page = 200;

    /**
     * @param  Closure(): ?string  $password
     */
    public function __construct(
        private string $baseUrl,
        private string $username,
        private Closure $password,
        private string $apiVersion = 'v2',
        private bool $verifyTls = true,
        private int $timeout = 15,
    ) {}

    public function key(): string
    {
        return 'haproxy';
    }

    public function name(): string
    {
        return 'HAProxy';
    }

    public function vendor(): string
    {
        return 'HAProxy Technologies';
    }

    public function capabilities(): CapabilitySet
    {
        /*
         * The read and the one write. Declaring the write grants nothing:
         * `resource_adapters.writes_enabled` is false until an operator turns
         * it on deliberately and audibly, and the registry makes an unenabled
         * capability *absent* rather than refused — so a screen cannot offer a
         * button this platform would then decline.
         */
        return CapabilitySet::of([
            Capability::LoadBalancerRead,
            Capability::LoadBalancerDrainWrite,
        ]);
    }

    public function limits(): RateLimits
    {
        /*
         * One request per backend section, so an estate with forty of them is
         * forty calls per sweep. The Data Plane API runs beside the process
         * actually passing traffic, and hammering it is a way to make a
         * balancer stutter.
         */
        return new RateLimits(perMinute: 120, concurrency: 2, batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        try {
            $response = $this->request()->get('/'.$this->apiVersion.'/info');
        } catch (Throwable) {
            return AdapterHealth::failing('The Data Plane API could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return AdapterHealth::failing('The Data Plane API refused the credential.');
        }

        if (! $response->successful()) {
            return AdapterHealth::failing('The Data Plane API answered '.$response->status().'.');
        }

        $version = $response->json('api.version');

        return new AdapterHealth(
            HealthState::Ok,
            'The Data Plane API answered.',
            remoteVersion: is_string($version) ? $version : null,
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @return list<Listener>
     */
    public function listeners(): array
    {
        $listeners = [];

        foreach ($this->configuredBackends() as $name) {
            $listeners[] = new Listener(
                key: $name,
                name: $name,
                backends: $this->serversIn($name),
            );
        }

        return $listeners;
    }

    public function backend(string $listenerKey, string $backendKey): ?Backend
    {
        foreach ($this->serversIn($listenerKey) as $server) {
            if ($server->key === $backendKey) {
                return $server;
            }
        }

        return null;
    }

    public function drain(string $listenerKey, string $backendKey): void
    {
        $this->setAdminState($listenerKey, $backendKey, 'drain');
    }

    public function undrain(string $listenerKey, string $backendKey): void
    {
        $this->setAdminState($listenerKey, $backendKey, 'ready');
    }

    /**
     * The whole body is the one field.
     *
     * Sending the server's other fields back would overwrite whatever
     * somebody changed between the read and the write — the smallest version
     * of the problem §6's guarded workflow exists for.
     */
    private function setAdminState(string $listenerKey, string $backendKey, string $state): void
    {
        try {
            $response = $this->request()->put(
                '/'.$this->apiVersion.'/services/haproxy/runtime/servers/'.rawurlencode($backendKey),
                ['admin_state' => $state],
            );
        } catch (Throwable) {
            throw BalancerUnreachable::noAnswer($this->key());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw BalancerUnreachable::refused($this->key());
        }

        if (! $response->successful()) {
            throw BalancerUnreachable::answered($this->key(), $response->status());
        }
    }

    /**
     * @return list<string>
     */
    private function configuredBackends(): array
    {
        $body = $this->read('/'.$this->apiVersion.'/services/haproxy/configuration/backends');

        // The configuration endpoints wrap their answer in `{_version, data}`.
        // Reading the envelope as the list answers nothing at all, silently.
        $rows = is_array($body['data'] ?? null) ? $body['data'] : [];
        $names = [];

        foreach (array_slice($rows, 0, self::Page) as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<Backend>
     */
    private function serversIn(string $listenerKey): array
    {
        $body = $this->read(
            '/'.$this->apiVersion.'/services/haproxy/runtime/servers',
            ['backend' => $listenerKey],
        );

        // The runtime endpoints answer a bare list, unlike the configuration
        // ones. Both shapes are handled rather than assumed.
        $rows = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $servers = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $row['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $address = is_string($row['address'] ?? null) ? $row['address'] : null;

            $servers[] = new Backend(
                key: $name,
                name: $name,
                state: $this->state($row),
                address: $address,
                port: is_int($row['port'] ?? null) ? $row['port'] : null,
                weight: is_int($row['weight'] ?? null) ? $row['weight'] : null,
                // The Data Plane API's runtime view does not carry a
                // connection count. Null rather than zero, because acting on
                // an invented zero is how somebody reboots a server that is
                // still serving.
                activeConnections: null,
                // The address is what the graph is most likely to know this
                // machine by, and a node that does not exist simply produces
                // no edge.
                nodeKey: $address,
            );
        }

        return $servers;
    }

    /**
     * The administrative state wins over the operational one.
     *
     * A server somebody drained is still operationally up and must not read
     * as taking traffic; a server in maintenance is not failing a health
     * check. `stopping` is the operational way of saying draining, which a
     * server reaches on its own.
     *
     * @param  array<string, mixed>  $row
     */
    private function state(array $row): BackendState
    {
        $admin = is_string($row['admin_state'] ?? null) ? $row['admin_state'] : null;

        if ($admin === 'drain') {
            return BackendState::Draining;
        }

        if ($admin === 'maint') {
            return BackendState::Disabled;
        }

        return match ($row['operational_state'] ?? null) {
            'up' => BackendState::Up,
            'stopping' => BackendState::Draining,
            'down' => BackendState::Down,
            default => BackendState::Unknown,
        };
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private function read(string $path, array $query = []): array
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable) {
            throw BalancerUnreachable::noAnswer($this->key());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw BalancerUnreachable::refused($this->key());
        }

        if (! $response->successful()) {
            throw BalancerUnreachable::answered($this->key(), $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw BalancerUnreachable::unreadable($this->key(), 'no list where one was expected');
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->withOptions(['verify' => $this->verifyTls]);

        $password = ($this->password)();

        // Basic rather than a bearer token: the Data Plane API authenticates
        // against HAProxy's own userlist. A missing password still makes the
        // request, so the balancer's own 401 is what the operator is told —
        // "nothing is configured" and "the credential is wrong" would
        // otherwise be the same message.
        return is_string($password) && $password !== ''
            ? $request->withBasicAuth($this->username, $password)
            : $request;
    }
}
