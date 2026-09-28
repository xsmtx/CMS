<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Carbon\CarbonImmutable;

/**
 * What one meter measured over one closed period (§25).
 *
 * **A quantity and a unit, never an amount.** What a gigabyte costs is the
 * seller's, and a meter that returned money would be a module setting prices
 * — which is the one thing a metering source must not be able to do.
 *
 * **The period is closed.** A reading for a month that has not ended is a
 * number that will change, and this platform writes it onto an invoice that
 * cannot (ADR 0023). An adapter that can only answer "so far" answers for the
 * last period that ended.
 *
 * `quantity` is a float because it is a measurement — the same exception
 * `ddos_events` peaks and telemetry take. Nothing monetary is a float
 * anywhere in this product, and nothing here is monetary.
 */
final readonly class UsageReading
{
    public function __construct(
        /** The meter this is for, as the source names it: `bandwidth.out`. */
        public string $meterKey,
        /** Which service it is about, in the source's own words. */
        public string $serviceKey,
        public float $quantity,
        public UsageUnit $unit,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
    ) {}
}
