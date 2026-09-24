<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * How hard an adapter may be pushed, declared by the adapter and enforced by
 * core.
 *
 * The direction matters. An adapter that throttles itself is an adapter whose
 * author has written a queue, badly, and has done it once per package — with its
 * own idea of a window, its own sleep, and no way for an operator to see why a
 * sweep is taking nine minutes. The adapter knows the number because it knows
 * the vendor; the platform knows how to wait because it already has a queue, a
 * scheduler and an operations table.
 *
 * `concurrency` is separate from `perMinute` and is not a refinement of it: a
 * FortiGate that tolerates 300 requests a minute may still fall over on the
 * fourth simultaneous connection, and those are different limits with different
 * symptoms.
 */
final readonly class RateLimits
{
    public function __construct(
        /** Requests per minute. Zero means the adapter declares no limit. */
        public int $perMinute = 0,
        /** Simultaneous requests. Zero means the adapter declares no limit. */
        public int $concurrency = 0,
        /**
         * How many targets to ask about in one call, where the protocol allows
         * batching. A poller that can answer for 500 hosts in one query should
         * say so, or core will ask 500 times politely.
         */
        public int $batchSize = 0,
    ) {}

    public static function unlimited(): self
    {
        return new self;
    }

    public function isUnlimited(): bool
    {
        return $this->perMinute === 0 && $this->concurrency === 0;
    }
}
