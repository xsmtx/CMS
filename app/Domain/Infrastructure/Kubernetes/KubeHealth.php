<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Kubernetes;

/**
 * What a cluster says about one of its own parts (§25).
 *
 * The same four members `StorageHealth` has, and its own enum rather than a
 * shared one for the reason `FindingSeverity` is separate from
 * `AlertSeverity`: these words answer "what condition is this object in",
 * where `HealthState` answers "did the adapter reach the thing at all". A
 * cluster that answers perfectly about a node that is `NotReady` is a healthy
 * adapter reporting an unhealthy node, and one enum doing both jobs would make
 * those two indistinguishable.
 *
 * `Degraded` is the member a three-state scale loses: a deployment with three
 * of four replicas running is serving traffic and is one failure from not.
 *
 * `Unknown` is what a source that does not say gets, and deliberately not
 * `Critical` — a cluster that reported no condition has not reported a bad
 * one, and a screen that drew it red would teach an operator to ignore red.
 */
enum KubeHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Critical = 'critical';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'infrastructure.kubernetes.health.'.$this->value;
    }

    /**
     * The tone a screen draws it in.
     *
     * A word `status.ts` knows, which `VocabularyTest` pins for every enum in
     * this product that has a `tone()`.
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
