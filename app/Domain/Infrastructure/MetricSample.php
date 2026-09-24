<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use Carbon\CarbonImmutable;

/**
 * A reading in this platform's own vocabulary and units.
 *
 * What comes out of the normalizer and the only shape anything writes to
 * `resource_metrics`. The type is the guarantee: a `MetricSample` cannot hold a
 * percentage or a name no screen knows, because there was nowhere to put either.
 */
final readonly class MetricSample
{
    public function __construct(
        public string $target,
        public MetricKind $metric,
        public float $value,
        public CarbonImmutable $sampledAt,
        public ?int $staleAfterSeconds = null,
    ) {}

    public function unit(): MetricUnit
    {
        return $this->metric->unit();
    }

    /**
     * Whether this reading has outlived its usefulness, as of now.
     *
     * A sample with no declared freshness is never stale, which is the right
     * default for a reading that came from a database rather than from a poller:
     * an account count does not go out of date, it just changes.
     */
    public function isStale(?CarbonImmutable $now = null): bool
    {
        if ($this->staleAfterSeconds === null) {
            return false;
        }

        $age = ($now ?? CarbonImmutable::now())->getTimestamp() - $this->sampledAt->getTimestamp();

        return $age > $this->staleAfterSeconds;
    }
}
