<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Power;

/**
 * What a UPS says about itself (§11).
 *
 * **`onBattery` is the field somebody is woken for**, and it is separate from
 * the charge for a reason: a UPS at 100% charge that is on battery has lost
 * its mains and is counting down, which is the most urgent state in this
 * whole family and looks perfectly healthy if only the percentage is read.
 *
 * `runtimeSeconds` is what the unit estimates it has left at the current
 * load. It moves when the load moves, which is why it is telemetry rather
 * than a fact — and why `CapacityForecast` has no business with it: a
 * straight line through a runtime estimate is a line through somebody else's
 * arithmetic.
 */
final readonly class UpsStatus
{
    public function __construct(
        public string $key,
        public string $name,
        /** Running on its battery rather than on mains. */
        public ?bool $onBattery = null,
        public ?float $batteryPercent = null,
        public ?int $runtimeSeconds = null,
        public ?float $loadPercent = null,
        public ?float $watts = null,
        /** The unit's own alarm text, where it has one. Its words, not ours. */
        public ?string $alarm = null,
    ) {}
}
