<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Kubernetes;

/**
 * A machine in a cluster (§25).
 *
 * **`hostname` is what makes this worth discovering at all.** A cluster node
 * and a `servers` row are usually the same machine wearing two names, and
 * matching them is what lets the graph answer "who is affected if this node
 * drains" — the question §25 names. The match is by hostname, lower-cased,
 * and **ambiguity is refused**: the `RecordSamples::byHostname()` rule, for
 * the same reason, because a workload attached to the wrong machine is worse
 * than one nobody placed.
 *
 * **`schedulable` is not `health`.** A node somebody cordoned on purpose and a
 * node that stopped answering are different things to act on, and collapsing
 * them would make a planned drain look like an outage — the distinction
 * `ServerStatus` draws between `maintenance` and `offline`, and `PortState`
 * between `Disabled` and `Down`.
 */
final readonly class KubeNode
{
    public function __construct(
        public string $key,
        public string $name,
        public KubeHealth $health = KubeHealth::Unknown,
        /** False when somebody cordoned it, which is a decision rather than a fault. */
        public bool $schedulable = true,
        public ?string $hostname = null,
        public ?string $kubeletVersion = null,
        public ?int $pods = null,
    ) {}
}
