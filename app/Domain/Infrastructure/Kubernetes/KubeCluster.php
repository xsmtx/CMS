<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Kubernetes;

/**
 * One cluster, as little of it as this platform needs (§25).
 *
 * §25 asks for "hosting-service context rather than replacing Rancher", and
 * that sentence is the whole specification: a customer rings up, an operator
 * needs to know which of their services is on the node that is misbehaving,
 * and nothing else here belongs in a hosting platform.
 *
 * So there is no spec, no events, no logs and no `kubectl`. What the graph
 * gets is identity and relationships, which is what the graph is for
 * (ADR 0043).
 */
final readonly class KubeCluster
{
    public function __construct(
        public string $key,
        public string $name,
        public KubeHealth $health = KubeHealth::Unknown,
        public ?string $version = null,
        public ?string $distribution = null,
    ) {}
}
