<?php

declare(strict_types=1);

namespace InfraCMS\HypervisorProxmox;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\HypervisorWriter;
use App\Domain\Infrastructure\Exceptions\HypervisorUnreachable;
use App\Domain\Infrastructure\RateLimits;
use App\Domain\Infrastructure\Virtualisation\HypervisorHost;
use App\Domain\Infrastructure\Virtualisation\MachineState;
use App\Domain\Infrastructure\Virtualisation\PowerAction;
use App\Domain\Infrastructure\Virtualisation\VirtualMachine;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proxmox VE, over its API.
 *
 * Five things about that API that only a careful read turns up, each pinned
 * by a test:
 *
 * - **A VMID is unique in the cluster, not on a node**, but every call that
 *   acts on a machine needs the node in the path. So the key is
 *   `node/type/vmid` — enough to address it and stable, where a bare vmid
 *   would need a lookup before every action and a bare name would move.
 * - **`status` has three values and one of them is not a third state.** A
 *   machine reports `running` or `stopped`; a paused one reports `running`
 *   with `qmpstatus: paused`. Reading only `status` would show a paused
 *   machine as serving traffic, which is exactly the machine that is holding
 *   all its memory and answering nothing.
 * - **Everything is wrapped in `{data: …}`**, and a failed call still answers
 *   200 with `data: null` for some endpoints. Reading the envelope as the
 *   answer produces an empty fleet, silently.
 * - **`shutdown` and `stop` are different endpoints**, not one endpoint with
 *   a flag: `shutdown` asks the guest, `stop` cuts the power. Collapsing them
 *   would mean an operator could not choose, on the one action where the
 *   choice is the whole decision.
 * - **A container is `lxc` and a machine is `qemu`**, with parallel but not
 *   identical endpoints. Both are read because both are things a customer is
 *   on; the type travels in the key so the write half knows which path to
 *   use.
 *
 * It has never talked to a real Proxmox cluster. Every request shape and
 * every parse here is tested against faked HTTP, which proves the code and
 * not the integration.
 */
final readonly class ProxmoxFleetProvider implements HypervisorWriter
{
    /**
     * @param  Closure(): ?string  $secret
     */
    public function __construct(
        private string $baseUrl,
        private string $tokenId,
        private Closure $secret,
        private bool $includeContainers = true,
        private bool $verifyTls = true,
        private int $timeout = 20,
    ) {}

    public function key(): string
    {
        return 'proxmox-fleet';
    }

    public function name(): string
    {
        return 'Proxmox VE (fleet)';
    }

    public function vendor(): string
    {
        return 'Proxmox';
    }

    public function capabilities(): CapabilitySet
    {
        /*
         * The reads and the one write. Creating, destroying, snapshotting and
         * migrating are all things this API would accept and none of them is
         * declared: provisioning is a different seam, and the rest have no
         * guarded workflow behind them yet.
         */
        return CapabilitySet::of([
            Capability::VirtualMachineRead,
            Capability::VirtualMachinePowerWrite,
        ]);
    }

    public function limits(): RateLimits
    {
        /*
         * One call per node for machines, plus one for containers. A cluster
         * of thirty nodes is sixty calls a sweep, and the API runs on the
         * same machines as the guests.
         */
        return new RateLimits(perMinute: 120, concurrency: 2, batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        try {
            $response = $this->request()->get('/api2/json/version');
        } catch (Throwable) {
            return AdapterHealth::failing('The Proxmox API could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return AdapterHealth::failing('The Proxmox API refused the credential.');
        }

        if (! $response->successful()) {
            return AdapterHealth::failing('The Proxmox API answered '.$response->status().'.');
        }

        $version = $response->json('data.version');

        return new AdapterHealth(
            HealthState::Ok,
            'The Proxmox API answered.',
            remoteVersion: is_string($version) ? $version : null,
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @return list<HypervisorHost>
     */
    public function hosts(): array
    {
        $hosts = [];

        foreach ($this->rows('/api2/json/nodes') as $row) {
            $name = $this->text($row, 'node');

            if ($name === null) {
                continue;
            }

            $hosts[] = new HypervisorHost(
                key: $name,
                name: $name,
                // `online`/`offline`/`unknown`. The third is a real answer and
                // becomes null: a node the cluster cannot see has not failed,
                // and this is a screen somebody acts on at three in the
                // morning.
                online: match ($this->text($row, 'status')) {
                    'online' => true,
                    'offline' => false,
                    default => null,
                },
                vcpus: $this->number($row, 'maxcpu'),
                memoryBytes: $this->number($row, 'maxmem'),
                memoryUsedBytes: $this->number($row, 'mem'),
                // Proxmox reports CPU as a fraction of one; the normalizer
                // wants a ratio, which is the same thing.
                cpuUtilisation: $this->decimal($row, 'cpu'),
                uptimeSeconds: $this->number($row, 'uptime'),
            );
        }

        return $hosts;
    }

    /**
     * @return list<VirtualMachine>
     */
    public function machines(): array
    {
        $machines = [];

        foreach ($this->hosts() as $host) {
            $types = $this->includeContainers ? ['qemu', 'lxc'] : ['qemu'];

            foreach ($types as $type) {
                foreach ($this->rows('/api2/json/nodes/'.rawurlencode($host->key).'/'.$type) as $row) {
                    $machine = $this->machineFrom($host->key, $type, $row);

                    if ($machine instanceof VirtualMachine) {
                        $machines[] = $machine;
                    }
                }
            }
        }

        return $machines;
    }

    public function machine(string $key): ?VirtualMachine
    {
        [$node, $type, $vmid] = $this->split($key);

        $body = $this->read('/api2/json/nodes/'.rawurlencode($node).'/'.$type.'/'.rawurlencode($vmid).'/status/current');
        $row = is_array($body['data'] ?? null) ? $body['data'] : null;

        if ($row === null) {
            return null;
        }

        // The status endpoint answers without the vmid it was asked about.
        $row['vmid'] ??= $vmid;

        return $this->machineFrom($node, $type, $row);
    }

    public function power(string $key, PowerAction $action): void
    {
        [$node, $type, $vmid] = $this->split($key);

        /*
         * `shutdown` and `stop` are different endpoints, not one with a flag.
         * One asks the guest and one cuts the power, and on the single action
         * where the choice is the whole decision an operator must be able to
         * make it.
         */
        $endpoint = match ($action) {
            PowerAction::Start => 'start',
            PowerAction::Shutdown => 'shutdown',
            PowerAction::PowerOff => 'stop',
            PowerAction::Reboot => 'reboot',
        };

        $path = '/api2/json/nodes/'.rawurlencode($node).'/'.$type.'/'.rawurlencode($vmid).'/status/'.$endpoint;

        try {
            $response = $this->request()->post($path);
        } catch (Throwable) {
            throw HypervisorUnreachable::noAnswer($this->key());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw HypervisorUnreachable::refused($this->key());
        }

        if (! $response->successful()) {
            throw HypervisorUnreachable::answered($this->key(), $response->status());
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function machineFrom(string $node, string $type, array $row): ?VirtualMachine
    {
        $vmid = $this->text($row, 'vmid');

        if ($vmid === null) {
            return null;
        }

        return new VirtualMachine(
            // Enough to address it and stable. A bare vmid would need a
            // lookup before every action and a bare name would move.
            key: $node.'/'.$type.'/'.$vmid,
            name: $this->text($row, 'name') ?? $vmid,
            state: $this->state($row),
            hostKey: $node,
            vcpus: $this->number($row, 'cpus') ?? $this->number($row, 'maxcpu'),
            memoryBytes: $this->number($row, 'maxmem'),
            diskBytes: $this->number($row, 'maxdisk'),
            uptimeSeconds: $this->number($row, 'uptime'),
            kind: $type,
        );
    }

    /**
     * A paused machine reports `running`, with `qmpstatus: paused`.
     *
     * Reading only `status` would show it as serving traffic — and it is
     * exactly the machine holding all its memory and answering nothing.
     *
     * @param  array<string, mixed>  $row
     */
    private function state(array $row): MachineState
    {
        $qmp = $this->text($row, 'qmpstatus');

        if ($qmp === 'paused' || $qmp === 'prelaunch') {
            return MachineState::Paused;
        }

        return match ($this->text($row, 'status')) {
            'running' => MachineState::Running,
            'stopped' => MachineState::Stopped,
            default => MachineState::Unknown,
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function split(string $key): array
    {
        $parts = explode('/', $key);

        if (count($parts) !== 3 || $parts[0] === '' || $parts[2] === '') {
            throw HypervisorUnreachable::unreadable($this->key(), 'a machine key it cannot address');
        }

        // Only the two types this adapter reads. A path segment built from a
        // stored string is a request to whatever that string says.
        $type = $parts[1] === 'lxc' ? 'lxc' : 'qemu';

        return [$parts[0], $type, $parts[2]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $path): array
    {
        $body = $this->read($path);

        // Everything is wrapped in `{data: …}`, and some endpoints answer 200
        // with `data: null`. Reading the envelope as the answer produces an
        // empty fleet, silently.
        $rows = is_array($body['data'] ?? null) ? $body['data'] : [];
        $out = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<mixed>
     */
    private function read(string $path): array
    {
        try {
            $response = $this->request()->get($path);
        } catch (Throwable) {
            throw HypervisorUnreachable::noAnswer($this->key());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw HypervisorUnreachable::refused($this->key());
        }

        if (! $response->successful()) {
            throw HypervisorUnreachable::answered($this->key(), $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw HypervisorUnreachable::unreadable($this->key(), 'no object where one was expected');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function number(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_int($value) || (is_float($value) && is_finite($value)) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function decimal(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        return is_int($value) || (is_float($value) && is_finite($value)) ? (float) $value : null;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->withOptions(['verify' => $this->verifyTls]);

        $secret = ($this->secret)();

        /*
         * Proxmox's own scheme, which is neither Bearer nor Basic:
         * `Authorization: PVEAPIToken=user@realm!tokenid=secret`. A missing
         * secret still makes the request, so the cluster's own 401 is what
         * the operator is told — "nothing is configured" and "the credential
         * is wrong" would otherwise be the same message.
         */
        return is_string($secret) && $secret !== ''
            ? $request->withHeaders([
                'Authorization' => 'PVEAPIToken='.$this->tokenId.'='.$secret,
            ])
            : $request;
    }
}
