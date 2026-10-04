<?php

declare(strict_types=1);

namespace InfraCMS\Kubernetes;

use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Infrastructure\Capability;
use App\Domain\Infrastructure\CapabilitySet;
use App\Domain\Infrastructure\Contracts\KubernetesProvider;
use App\Domain\Infrastructure\Exceptions\DeviceUnreachable;
use App\Domain\Infrastructure\Kubernetes\KubeCluster;
use App\Domain\Infrastructure\Kubernetes\KubeHealth;
use App\Domain\Infrastructure\Kubernetes\KubeNode;
use App\Domain\Infrastructure\Kubernetes\KubeWorkload;
use App\Domain\Infrastructure\RateLimits;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Kubernetes API, read through a service account.
 *
 * Named `KubernetesProviderAdapter` rather than `KubernetesProvider`, because
 * that name belongs to the contract it implements — two classes one `use`
 * statement apart would be two classes somebody has to disambiguate on every
 * line that mentions either.
 *
 * Four particulars of that API that only a careful read turns up, each pinned
 * by a test:
 *
 * - **A cluster does not know its own name.** There is no endpoint for it, so
 *   the name is configuration and the key is the address. A node key built
 *   from a name an operator can retype would move the moment they did.
 * - **A node's condition is a list, not a field.** `status.conditions` holds
 *   several, and the one that answers "is this node usable" is `Ready` —
 *   whose `status` is the string `"True"`, `"False"` or `"Unknown"`. Reading
 *   the first condition in the list answers whatever the kubelet happened to
 *   report first.
 * - **Cordoned is `spec.unschedulable`, which is separate from Ready.** A node
 *   somebody drained on purpose is Ready and unschedulable, and collapsing the
 *   two would make planned maintenance look like a fault.
 * - **A pod names its node in `spec.nodeName`, and an unscheduled pod has
 *   none.** Reading it as the empty string would place every pending pod on a
 *   node called "", which is one more node than the cluster has.
 *
 * **Lists are capped and `metadata.continue` is ignored.** A cluster with more
 * pods than the cap is a cluster this adapter reports the first page of, which
 * is honest; paging through forty thousand pods on an hourly sweep is a
 * platform that spends its day doing that.
 *
 * It has never talked to a real cluster. Every request shape and every parse
 * here is tested against faked HTTP, which proves the code and not the
 * integration.
 */
final readonly class KubernetesProviderAdapter implements KubernetesProvider
{
    private const int PAGE = 500;

    /**
     * @param  Closure(): ?string  $token
     */
    public function __construct(
        private string $baseUrl,
        private string $clusterName,
        private Closure $token,
        private bool $verifyTls = true,
        private int $timeout = 15,
    ) {}

    public function key(): string
    {
        return 'kubernetes';
    }

    public function name(): string
    {
        return $this->clusterName === '' ? 'Kubernetes' : $this->clusterName;
    }

    public function vendor(): string
    {
        return 'Kubernetes';
    }

    public function capabilities(): CapabilitySet
    {
        // One read, and no write beside it. Everything this connection would
        // accept belongs behind the guarded workflow.
        return CapabilitySet::of([Capability::KubernetesRead]);
    }

    public function limits(): RateLimits
    {
        /*
         * An API server shares a control plane with the thing scheduling
         * workloads, and a list of every pod is one of the more expensive
         * calls it serves. Two at a time.
         */
        return new RateLimits(perMinute: 60, concurrency: 2, batchSize: 0);
    }

    public function health(): AdapterHealth
    {
        try {
            $response = $this->request()->get('/version');
        } catch (Throwable) {
            return AdapterHealth::failing('The cluster could not be reached.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return AdapterHealth::failing('The cluster refused the credential.');
        }

        if (! $response->successful()) {
            return AdapterHealth::failing('The cluster answered '.$response->status().'.');
        }

        $version = $response->json('gitVersion');

        return new AdapterHealth(
            HealthState::Ok,
            'The cluster answered.',
            remoteVersion: is_string($version) ? $version : null,
            checkedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @return list<KubeCluster>
     */
    public function clusters(): array
    {
        $response = $this->get('/version', []);

        $version = $response->json('gitVersion');

        return [new KubeCluster(
            // The address, because a cluster has no name of its own and a name
            // an operator can retype would move the node keys with it.
            key: $this->clusterKey(),
            name: $this->clusterName === '' ? $this->clusterKey() : $this->clusterName,
            health: KubeHealth::Healthy,
            version: is_string($version) ? $version : null,
        )];
    }

    /**
     * @return list<KubeNode>
     */
    public function nodes(string $cluster): array
    {
        $rows = $this->items($this->get('/api/v1/nodes', ['limit' => self::PAGE]));

        $nodes = [];

        foreach ($rows as $row) {
            $name = $this->metadataName($row);

            if ($name === '') {
                continue;
            }

            $status = is_array($row['status'] ?? null) ? $row['status'] : [];
            $spec = is_array($row['spec'] ?? null) ? $row['spec'] : [];
            $info = is_array($status['nodeInfo'] ?? null) ? $status['nodeInfo'] : [];

            $nodes[] = new KubeNode(
                key: $name,
                name: $name,
                health: $this->readiness($status),
                // Separate from readiness: somebody drained this on purpose.
                schedulable: ($spec['unschedulable'] ?? false) !== true,
                hostname: $this->hostnameOf($status, $name),
                kubeletVersion: is_string($info['kubeletVersion'] ?? null) ? $info['kubeletVersion'] : null,
            );
        }

        return $nodes;
    }

    /**
     * @return list<KubeWorkload>
     */
    public function workloads(string $cluster): array
    {
        $deployments = $this->items($this->get('/apis/apps/v1/deployments', ['limit' => self::PAGE]));
        $pods = $this->items($this->get('/api/v1/pods', ['limit' => self::PAGE]));

        /*
         * Which nodes each workload's pods are actually on, built from the pod
         * list because a deployment does not say. This is the whole reason
         * pods are read at all — §25's question is who is affected when a node
         * drains, and only the running pods answer it.
         *
         * @var array<string, array<string, true>> $placement
         */
        $placement = [];

        foreach ($pods as $pod) {
            $meta = is_array($pod['metadata'] ?? null) ? $pod['metadata'] : [];
            $spec = is_array($pod['spec'] ?? null) ? $pod['spec'] : [];

            $node = $spec['nodeName'] ?? null;

            // An unscheduled pod has no node, and reading that as the empty
            // string would place it on a node called "".
            if (! is_string($node) || $node === '') {
                continue;
            }

            $owner = $this->workloadOf($meta);

            if ($owner === null) {
                continue;
            }

            $placement[$owner][$node] = true;
        }

        $workloads = [];

        foreach ($deployments as $row) {
            $name = $this->metadataName($row);
            $namespace = $this->metadataNamespace($row);

            if ($name === '' || $namespace === '') {
                continue;
            }

            $status = is_array($row['status'] ?? null) ? $row['status'] : [];

            $ready = is_int($status['readyReplicas'] ?? null) ? $status['readyReplicas'] : 0;
            $desired = is_int($status['replicas'] ?? null) ? $status['replicas'] : null;

            $key = $namespace.'/'.$name;

            $workloads[] = new KubeWorkload(
                key: $key,
                name: $name,
                namespace: $namespace,
                kind: 'Deployment',
                nodeKeys: array_keys($placement[$key] ?? []),
                health: $this->replicaHealth($ready, $desired),
                ready: $ready,
                desired: $desired,
            );
        }

        return $workloads;
    }

    /**
     * Which workload a pod belongs to, by its generated name.
     *
     * A pod's owner reference points at its ReplicaSet rather than at the
     * Deployment, and resolving that would be a third list on every sweep. The
     * labels are what a deployment puts there, so `app.kubernetes.io/name`
     * then the pod's own `metadata.labels.app` is the cheap answer — and a pod
     * with neither is skipped rather than guessed at, because a workload this
     * adapter invented is an impact answer nobody can check.
     *
     * @param  array<string, mixed>  $meta
     */
    private function workloadOf(array $meta): ?string
    {
        $namespace = is_string($meta['namespace'] ?? null) ? $meta['namespace'] : '';
        $labels = is_array($meta['labels'] ?? null) ? $meta['labels'] : [];

        foreach (['app.kubernetes.io/name', 'app'] as $label) {
            $value = $labels[$label] ?? null;

            if (is_string($value) && $value !== '' && $namespace !== '') {
                return $namespace.'/'.$value;
            }
        }

        return null;
    }

    /**
     * The `Ready` condition, which is one of several and not the first.
     *
     * Its `status` is the string `"True"`, `"False"` or `"Unknown"` — a JSON
     * boolean would be the obvious reading and is wrong, and `"Unknown"` is
     * what a node whose kubelet stopped reporting says.
     *
     * @param  array<string, mixed>  $status
     */
    private function readiness(array $status): KubeHealth
    {
        $conditions = is_array($status['conditions'] ?? null) ? $status['conditions'] : [];

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ($condition['type'] ?? null) !== 'Ready') {
                continue;
            }

            return match ($condition['status'] ?? null) {
                'True' => KubeHealth::Healthy,
                'False' => KubeHealth::Critical,
                default => KubeHealth::Unknown,
            };
        }

        return KubeHealth::Unknown;
    }

    private function replicaHealth(int $ready, ?int $desired): KubeHealth
    {
        if ($desired === null) {
            return KubeHealth::Unknown;
        }

        if ($ready >= $desired) {
            return KubeHealth::Healthy;
        }

        // None running is an outage; some running is the window in which
        // somebody can act, which is the member a three-state scale loses.
        return $ready === 0 ? KubeHealth::Critical : KubeHealth::Degraded;
    }

    /**
     * What this platform would call the machine, so a `servers` row can be
     * matched to it.
     *
     * `status.addresses` carries a `Hostname` entry, and a node's own name is
     * the fallback — which on most clusters is the hostname anyway.
     *
     * @param  array<string, mixed>  $status
     */
    private function hostnameOf(array $status, string $name): string
    {
        $addresses = is_array($status['addresses'] ?? null) ? $status['addresses'] : [];

        foreach ($addresses as $address) {
            if (is_array($address) && ($address['type'] ?? null) === 'Hostname') {
                $value = $address['address'] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function metadataName(array $row): string
    {
        $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

        return is_string($meta['name'] ?? null) ? $meta['name'] : '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function metadataNamespace(array $row): string
    {
        $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

        return is_string($meta['namespace'] ?? null) ? $meta['namespace'] : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(Response $response): array
    {
        $items = $response->json('items');

        if (! is_array($items)) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    private function clusterKey(): string
    {
        return preg_replace('/[^a-z0-9.-]+/', '-', mb_strtolower(
            (string) parse_url($this->baseUrl, PHP_URL_HOST),
        )) ?? 'cluster';
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query): Response
    {
        $target = $this->clusterKey();

        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable) {
            throw DeviceUnreachable::noAnswer($target);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw DeviceUnreachable::refused($target);
        }

        if (! $response->successful()) {
            throw DeviceUnreachable::unreadable($target, 'the cluster answered '.$response->status());
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson()
            ->withOptions(['verify' => $this->verifyTls]);

        $token = ($this->token)();

        return is_string($token) && $token !== ''
            ? $request->withToken($token)
            : $request;
    }
}
