<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Virtualisation;

/**
 * One machine that runs other machines (§10).
 *
 * A Proxmox node, an ESXi host, a Hyper-V server. `online` is nullable and a
 * null is “the cluster did not say” rather than “offline” — a host nobody
 * asked about is not a host that has failed, and this is the screen somebody
 * would act on at three in the morning.
 *
 * Capacity comes through the metric normalizer like every other number in
 * this product; what is here is the identity and the shape.
 */
final readonly class HypervisorHost
{
    public function __construct(
        public string $key,
        public string $name,
        public ?bool $online = null,
        public ?int $vcpus = null,
        public ?int $memoryBytes = null,
        public ?int $memoryUsedBytes = null,
        public ?float $cpuUtilisation = null,
        public ?int $uptimeSeconds = null,
        /** The cluster it belongs to, where there is one. */
        public ?string $clusterKey = null,
    ) {}
}
