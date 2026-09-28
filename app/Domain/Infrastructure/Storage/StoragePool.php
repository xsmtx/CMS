<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Storage;

/**
 * A place volumes live, as a storage system describes it (§9).
 *
 * A Ceph pool, a ZFS zpool, a NetApp aggregate, an S3 bucket. `technology` is
 * the source's own word for what it is, free text for the reason
 * `ProtectedResource::$resourceType` is: core has no business telling a vendor
 * what its own noun is, and an enum here would reject one nobody has thought
 * of yet.
 *
 * **Capacity is nullable and null means the source did not say.** An S3
 * bucket has no total, and answering zero there would draw a pool as full.
 * `CapacityForecast` already declines to answer more often than it answers,
 * and that rule starts here.
 */
final readonly class StoragePool
{
    public function __construct(
        /** The source's own identifier, stable across runs. */
        public string $key,
        public string $name,
        public StorageHealth $health = StorageHealth::Unknown,
        public ?string $technology = null,
        public ?int $totalBytes = null,
        public ?int $usedBytes = null,
        public ?float $iops = null,
        public ?float $latencyMs = null,
        /**
         * How many copies of an object this pool keeps, where the system says.
         *
         * Not a metric: it is a configuration somebody chose, and a chart of
         * it would be a flat line. It is on the node so an operator reading
         * "degraded" can see whether degraded means one copy left or two.
         */
        public ?int $replicas = null,
    ) {}
}
