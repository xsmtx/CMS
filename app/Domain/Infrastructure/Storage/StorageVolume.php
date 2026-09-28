<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Storage;

/**
 * A volume a storage system is serving (§9).
 *
 * An RBD image, a ZFS dataset, a LUN, a share. It is a graph node rather than
 * a table row, which `ResourceKind`'s own docblock names as the example: core
 * owns four kinds because it owns four kinds of row, and a storage volume is
 * a thing a module knows about.
 *
 * **`attachedToNodeKey` is the workload, and it is provider-side.** §9 asks
 * for the attached workloads, and what a storage system can actually say is
 * which host or hypervisor has the volume mounted — not which customer. The
 * customer is reached by walking the graph from there, which is what the
 * graph is for; an edge straight from a volume to a customer's service would
 * cross the organization boundary and be refused (ADR 0043), exactly as it
 * was for an IP address.
 *
 * A key the graph has no node for is **kept as an attribute and not made into
 * an edge**. A volume attached to a machine this installation has never heard
 * of is a true and useful thing to see; inventing a node for it would put
 * hardware into the graph on the word of one adapter.
 */
final readonly class StorageVolume
{
    public function __construct(
        /** The source's own identifier, stable across runs. */
        public string $key,
        public string $name,
        /** The pool it lives in, where the source says which. */
        public ?string $poolKey = null,
        public StorageHealth $health = StorageHealth::Unknown,
        public ?int $sizeBytes = null,
        public ?int $usedBytes = null,
        /** The host or hypervisor this is mounted on, in that system's words. */
        public ?string $attachedToNodeKey = null,
    ) {}
}
