<?php

declare(strict_types=1);

namespace App\Application\Infrastructure;

use App\Domain\Infrastructure\MetricSample;

/**
 * What survived normalization, and what did not, and why not.
 *
 * The refusals are counted rather than discarded, because an adapter reporting a
 * metric this platform has never heard of is a thing somebody has to be told: it
 * is either a metric worth adding to `MetricKind` or an adapter reporting
 * nonsense, and both are decisions. Dropping them silently would make the
 * Telemetry screen say "everything is arriving" about a source sending nothing
 * usable.
 *
 * `unmapped` and `mismatched` are separate because the fixes are different. An
 * unmapped name is somebody's spelling this platform does not know. A mismatched
 * unit is a bug: an adapter offering bytes for a CPU ratio has misunderstood its
 * own source, and storing it would be wrong by a factor nobody can see.
 */
final readonly class NormalizedBatch
{
    /**
     * @param  list<MetricSample>  $samples
     * @param  array<string, int>  $unmapped  Source metric name => how many were dropped.
     * @param  array<string, int>  $mismatched  Source metric name => how many had the wrong dimension.
     */
    public function __construct(
        public array $samples = [],
        public array $unmapped = [],
        public array $mismatched = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->samples === [];
    }

    public function refusedCount(): int
    {
        return array_sum($this->unmapped) + array_sum($this->mismatched);
    }
}
