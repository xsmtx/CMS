<?php

declare(strict_types=1);

namespace InfraCMS\StorageCeph;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\StorageProvider;
use App\Domain\Infrastructure\Exceptions\StorageUnreachable;
use App\Domain\Infrastructure\RateLimits;
use App\Domain\Infrastructure\Storage\StorageHealth;
use App\Domain\Infrastructure\Storage\StoragePool;
use App\Domain\Infrastructure\Storage\StorageVolume;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ceph, over the manager's dashboard API.
 *
 * **Reads only.** Ceph will take a pool delete over this same API and it is
 * the most consequential call in the product's whole adapter surface; this
 * package declares no write capability at all, so core cannot offer a button
 * for one.
 *
 * Four things about Ceph that a careful read of its API turns up, each pinned
 * by a test:
 *
 * - **A pool's total is not reported; it is derived.** Ceph answers
 *   `bytes_used` and `max_avail` per pool, and `max_avail` is what is left
 *   *for that pool* after replication and the fullest OSD — so the total is
 *   the sum of the two and it moves when a different pool grows. That is the
 *   honest number and it is why a Ceph pool's capacity is not a constant.
 * - **A pool's health is its PG states, not the cluster's.** `HEALTH_WARN` is
 *   cluster-wide and says nothing about which pool is affected; `pg_status`
 *   is per pool and is what an operator actually reads. A pool whose PGs are
 *   `active+clean` is healthy inside a warning cluster, and saying otherwise
 *   would make every pool look broken because one OSD is near full.
 * - **`degraded` is not `critical`.** A pool recovering after a disk failure
 *   is serving every read and is one more failure from losing data. That is
 *   the window in which somebody can act, and collapsing it into either
 *   neighbour throws the window away.
 * - **An RBD image reports no health of its own**, so its health is
 *   `Unknown` rather than inherited from its pool. Inheriting would assert
 *   something Ceph did not say, and an image on a degraded pool is not itself
 *   degraded.
 *
 * It has never talked to a real Ceph cluster. Every request shape and every
 * parse here is tested against faked HTTP, which proves the code and not the
 * integration.
 */
final readonly class CephProvider implements StorageProvider
{
    /**
     * How many rows to take from a list endpoint.
     *
     * Larger than any pool count an operator maintains, smaller than an image
     * list nobody could read. A cluster with more is one this adapter reports
     * the first page of.
     */
    private const int Page = 500;

    /**
     * The API version this adapter speaks.
     *
     * Ceph negotiates by `Accept`, and asking for a version it does not have
     * answers 415 rather than guessing — which is the behaviour worth having:
     * an upgrade that changed the shape refuses loudly instead of parsing
     * into nulls.
     */
    private const string Accept = 'application/vnd.ceph.api.v1.0+json';

    /**
     * @param  Closure(): ?string  $token
     */
    public function __construct(
        private string $baseUrl,
        private Closure $token,
        private bool $verifyTls = true,
        private int $timeout = 20,
    ) {}

    public function key(): string
    {
        return 'ceph';
    }

    public function name(): string
    {
        return 'Ceph';
    }

    public function vendor(): string
    {
        return 'Ceph';
    }

    public function capabilities(): CapabilitySet
    {
        // The read, and none of the writes this cluster would accept.
        return CapabilitySet::of([Capability::StorageCapacityRead]);
    }

    public function limits(): RateLimits
    {
        /*
         * The manager daemon answers these from its own cache, so the calls
         * are cheap — but a recovering cluster's manager is busy, and two
         * inventory reads at once is a way to make a dashboard somebody is
         * watching stop responding.
         */
        return new RateLimits(perMinute: 60, concurrency: 2, batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        try {
            $response = $this->request()->get('/api/health/minimal');
        } catch (Throwable) {
            return AdapterHealth::failing('The Ceph manager could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return AdapterHealth::failing('The Ceph manager refused the credential.');
        }

        if (! $response->successful()) {
            return AdapterHealth::failing('The Ceph manager answered '.$response->status().'.');
        }

        $status = $response->json('health.status');
        $version = $response->json('mgr_map.active_name');

        /*
         * `HEALTH_WARN` is not an adapter failure. The adapter answered
         * perfectly; the cluster has something to say, and that belongs on
         * the pools it affects and on an alert rule, not on the row that says
         * whether this platform can talk to it. An adapter marked failing
         * because a cluster is rebalancing is an adapter an operator learns
         * to ignore.
         */
        return new AdapterHealth(
            $status === 'HEALTH_ERR' ? HealthState::Degraded : HealthState::Ok,
            $status === 'HEALTH_ERR'
                ? 'The Ceph manager answered, and the cluster reports an error.'
                : 'The Ceph manager answered.',
            remoteVersion: is_string($version) ? $version : null,
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @return list<StoragePool>
     */
    public function pools(): array
    {
        $pools = [];

        foreach ($this->rows('/api/pool', ['stats' => 'true']) as $row) {
            $name = $this->text($row, 'pool_name');

            if ($name === null) {
                continue;
            }

            $used = $this->stat($row, 'bytes_used');
            $available = $this->stat($row, 'max_avail');

            $pools[] = new StoragePool(
                // The pool id, not its name: a pool can be renamed and the id
                // cannot, and a node key that moved would leave the old node
                // behind as a pool that had apparently vanished.
                key: (string) ($this->text($row, 'pool') ?? $name),
                name: $name,
                health: $this->poolHealth($row),
                technology: $this->text($row, 'type'),
                /*
                 * Derived, because Ceph does not report a pool total. What is
                 * left for this pool after replication and the fullest OSD is
                 * `max_avail`, so used plus available is the honest figure —
                 * and it moves when a different pool grows, which is true of
                 * Ceph and surprising to everybody the first time.
                 */
                totalBytes: $used === null || $available === null ? null : $used + $available,
                usedBytes: $used,
                replicas: $this->number($row, 'size'),
            );
        }

        return $pools;
    }

    /**
     * @return list<StorageVolume>
     */
    public function volumes(): array
    {
        /*
         * The image endpoint names an image's pool by **name**, and a pool is
         * keyed here by its **id** — because an id survives a rename and a
         * name does not, which is the same reason a device is keyed on its
         * serial rather than its hostname. So the names are resolved back to
         * ids before anything is returned; without it every volume would name
         * a pool nothing here has, and the containment edge would never be
         * written. One extra read, once per sweep.
         */
        $idByName = [];

        foreach ($this->rows('/api/pool', ['stats' => 'false']) as $row) {
            $name = $this->text($row, 'pool_name');
            $id = $this->text($row, 'pool');

            if ($name !== null && $id !== null) {
                $idByName[$name] = $id;
            }
        }

        $volumes = [];

        /*
         * The image endpoint answers grouped by pool: a list of
         * `{pool_name, value: [image, ...]}`. Reading it as a flat list
         * answers nothing at all, silently.
         */
        foreach ($this->rows('/api/block/image', ['limit' => self::Page]) as $group) {
            $poolName = $this->text($group, 'pool_name');
            $poolKey = $poolName === null ? null : ($idByName[$poolName] ?? null);
            $images = is_array($group['value'] ?? null) ? $group['value'] : [];

            foreach ($images as $image) {
                if (! is_array($image)) {
                    continue;
                }

                $name = $this->text($image, 'name');

                if ($name === null) {
                    continue;
                }

                $volumes[] = new StorageVolume(
                    // Qualified by the pool's own name, which is what an
                    // operator sees in `rbd ls` and in the dashboard. Two
                    // pools may each hold a `vm-101-disk-0`.
                    key: ($poolName ?? 'rbd').'/'.$name,
                    name: $name,
                    // Null where the pool is one this read did not see, which
                    // writes no edge rather than a wrong one.
                    poolKey: $poolKey,
                    // Ceph says nothing about an image's own condition, and
                    // inheriting the pool's would assert something it did not
                    // say: an image on a degraded pool is not itself degraded.
                    health: StorageHealth::Unknown,
                    sizeBytes: $this->number($image, 'size'),
                    usedBytes: $this->number($image, 'disk_usage'),
                    // Which host has it mapped, where the cluster knows. Often
                    // nothing, because an image mapped by a hypervisor through
                    // librbd has no watcher the manager reports by name.
                    attachedToNodeKey: $this->watcher($image),
                );
            }
        }

        return $volumes;
    }

    /**
     * A pool's condition, read from its own placement groups.
     *
     * `pg_status` is a map of state to count: `{"active+clean": 128}`. The
     * states are compound and additive, so this looks for the words rather
     * than matching the whole string — `active+undersized+degraded` is one
     * state and contains two of the words below.
     *
     * The order matters: a pool that is both degraded and has an inactive PG
     * is critical, and checking "degraded" first would report the milder of
     * the two.
     *
     * @param  array<string, mixed>  $row
     */
    private function poolHealth(array $row): StorageHealth
    {
        $states = is_array($row['pg_status'] ?? null) ? array_keys($row['pg_status']) : [];

        if ($states === []) {
            return StorageHealth::Unknown;
        }

        $all = mb_strtolower(implode(' ', array_map(strval(...), $states)));

        foreach (['incomplete', 'down', 'stale', 'inconsistent'] as $word) {
            if (str_contains($all, $word)) {
                return StorageHealth::Critical;
            }
        }

        foreach (['degraded', 'undersized', 'recovering', 'backfill', 'remapped'] as $word) {
            if (str_contains($all, $word)) {
                return StorageHealth::Degraded;
            }
        }

        return str_contains($all, 'active+clean') ? StorageHealth::Healthy : StorageHealth::Unknown;
    }

    /**
     * Who has this image open, where the cluster names one.
     *
     * @param  array<string, mixed>  $image
     */
    private function watcher(array $image): ?string
    {
        $watchers = is_array($image['watchers'] ?? null) ? $image['watchers'] : [];

        foreach ($watchers as $watcher) {
            $address = is_array($watcher) ? ($watcher['address'] ?? null) : null;

            if (is_string($address) && $address !== '') {
                // The address without its port and nonce: Ceph writes
                // `10.0.0.4:0/1234567`, and the machine is the host.
                return explode(':', $address, 2)[0];
            }
        }

        return null;
    }

    /**
     * One of a pool's statistics.
     *
     * Ceph wraps each in `{latest, rate, rates}` when asked for stats. Reading
     * the wrapper as a number answers null and draws an empty pool.
     *
     * @param  array<string, mixed>  $row
     */
    private function stat(array $row, string $key): ?int
    {
        $stats = is_array($row['stats'] ?? null) ? $row['stats'] : [];
        $entry = $stats[$key] ?? null;

        if (is_array($entry)) {
            $entry = $entry['latest'] ?? null;
        }

        return is_int($entry) || (is_float($entry) && is_finite($entry)) ? (int) $entry : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $path, array $query = []): array
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable) {
            throw StorageUnreachable::noAnswer($this->key());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw StorageUnreachable::refused($this->key());
        }

        if (! $response->successful()) {
            throw StorageUnreachable::answered($this->key(), $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw StorageUnreachable::unreadable($this->key(), 'no list where one was expected');
        }

        $rows = [];

        foreach (array_slice($body, 0, self::Page) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : (is_int($value) ? (string) $value : null);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function number(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_int($value) || (is_float($value) && is_finite($value)) ? (int) $value : null;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders(['Accept' => self::Accept])
            ->withOptions(['verify' => $this->verifyTls]);

        $token = ($this->token)();

        return is_string($token) && $token !== ''
            ? $request->withToken($token)
            : $request;
    }
}
