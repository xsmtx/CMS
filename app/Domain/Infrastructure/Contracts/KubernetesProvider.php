<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Kubernetes\KubeCluster;
use App\Domain\Infrastructure\Kubernetes\KubeNode;
use App\Domain\Infrastructure\Kubernetes\KubeWorkload;

/**
 * Something that can say what a cluster is running (§25).
 *
 * **Hosting-service context, not a Rancher replacement**, which is the whole
 * of §25's specification and the reason there is no write half and no screen.
 * The graph already draws what this discovers — the Explorer, the impact
 * figures and the Telemetry screen read whatever is in it — and a Kubernetes
 * dashboard is what the handoff says not to build.
 *
 * **Reads only, and deliberately.** Scaling a deployment, draining a node and
 * deleting a namespace are all things a cluster would accept over this same
 * API, and every one of them belongs behind §6's guarded workflow rather than
 * behind a method anything could call — the rule the firewall write, the
 * certificate write and the DNS record write each state from their own side.
 *
 * Every method throws rather than returning an empty list a caller would read
 * as "the cluster is empty", which is the one answer that would have a sweep
 * retire an estate that is merely unreachable.
 */
interface KubernetesProvider extends InfrastructureAdapter
{
    /**
     * The clusters this adapter is configured to see.
     *
     * A list rather than a single answer, because one set of credentials often
     * reaches several clusters — and an adapter that reaches exactly one says
     * so by returning one.
     *
     * @return list<KubeCluster>
     */
    public function clusters(): array;

    /**
     * The machines in one cluster.
     *
     * @return list<KubeNode>
     */
    public function nodes(string $cluster): array;

    /**
     * What is running in one cluster, and which machines it is on.
     *
     * @return list<KubeWorkload>
     */
    public function workloads(string $cluster): array;
}
