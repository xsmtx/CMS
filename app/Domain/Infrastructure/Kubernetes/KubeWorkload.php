<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Kubernetes;

/**
 * Something running in a cluster (§25).
 *
 * A deployment, a statefulset, a daemonset — whatever the adapter can name.
 * `kind` is the tool's own word rather than an enum of ours: core cannot know
 * every workload type a cluster will grow, and an unrecognised one dropped
 * silently is a workload the impact query would miss.
 *
 * **`ready` and `desired` are two numbers, not a ratio.** Three of four
 * replicas running and three of three are different situations, and a
 * percentage loses which. Both are nullable because an adapter may not say,
 * and null is "it did not say" rather than zero — a workload drawn as having
 * no replicas running would be an outage this platform invented.
 *
 * **`nodeKeys` is which machines it is actually on**, which is the whole point
 * of discovering workloads: §25's question is who is affected when a node
 * drains, and only the running pods answer it.
 */
final readonly class KubeWorkload
{
    /**
     * @param  list<string>  $nodeKeys  The nodes its pods are on, as the
     *                                  adapter keys them.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $namespace,
        public string $kind,
        public array $nodeKeys = [],
        public KubeHealth $health = KubeHealth::Unknown,
        public ?int $ready = null,
        public ?int $desired = null,
    ) {}
}
