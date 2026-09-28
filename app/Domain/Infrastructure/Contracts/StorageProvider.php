<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Storage\StoragePool;
use App\Domain\Infrastructure\Storage\StorageVolume;

/**
 * Something that knows what it is storing (§9).
 *
 * Ceph, ZFS, TrueNAS, NetApp, a Dell array, an S3-compatible endpoint.
 *
 * **It reads and never provisions.** `Capability::StorageVolumeWrite` exists
 * and has no method here — the fourth time that pattern appears, after the
 * firewall policy, the DNS record and the certificate. Creating and deleting
 * volumes is provisioning, which this product already does through a
 * different seam, and a method here would be a second answer to it. Deleting
 * a volume is also the most destructive thing a storage API offers, and it
 * belongs behind a guarded workflow rather than behind a method anything
 * could call.
 *
 * **It answers the whole inventory, not a page.** A sweep replaces what this
 * source reported last time: a volume the adapter stops naming has been
 * destroyed or moved, and core retires its node rather than deleting it.
 *
 * **A read that failed must throw, never return `[]`.** An empty answer is
 * taken literally — every pool and volume this source had has gone — so a
 * source that is merely unreachable would retire an entire array. The same
 * rule `BackupProvider` states, for a failure that is just as loud.
 */
interface StorageProvider extends InfrastructureAdapter
{
    /**
     * Every pool this source is serving from.
     *
     * @return list<StoragePool>
     */
    public function pools(): array;

    /**
     * Every volume this source is serving.
     *
     * @return list<StorageVolume>
     */
    public function volumes(): array;
}
