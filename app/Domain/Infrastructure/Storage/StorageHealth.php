<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Storage;

/**
 * What a storage system says about its own condition (§9).
 *
 * Four members, because `Degraded` is the one that matters and the one a
 * three-state scale loses. A Ceph pool rebuilding after a disk failure is
 * serving every read and is one more failure from losing data; a ZFS pool
 * resilvering is the same. Collapsing that into "healthy" hides the window in
 * which somebody could act, and collapsing it into "critical" wakes them for
 * a pool that is fixing itself.
 *
 * `Unknown` is what a source that does not say gets, and it is deliberately
 * not `Critical`: a system that reported no condition has not reported a bad
 * one, and a screen that drew it red would teach an operator to ignore red.
 */
enum StorageHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Critical = 'critical';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'infrastructure.storage.health.'.$this->value;
    }

    /**
     * The tone a screen draws it in.
     *
     * A word `status.ts` knows, which `VocabularyTest` pins for every enum in
     * this product — `success` is not one of them, and the last enum to guess
     * drew the unknown mark on a backup that had worked.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Healthy => 'healthy',
            self::Degraded => 'warning',
            self::Critical => 'critical',
            self::Unknown => 'unknown',
        };
    }
}
