<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Power;

/**
 * One environmental sensor, and what it currently says (§11).
 *
 * **A reading or a state, never both.** Temperature, humidity and airflow
 * fill `value`; a leak, smoke or a door fills `triggered`. A sensor that
 * filled the wrong one is a sensor core refuses to record rather than
 * converts — the rule `RecordSamples` states about a unit from the wrong
 * dimension, applied to a different kind of wrongness.
 *
 * `triggered` is nullable and the null is "the sensor did not say". A leak
 * detector nobody heard from has not reported dry.
 */
final readonly class EnvironmentReading
{
    public function __construct(
        public string $key,
        public string $name,
        public SensorKind $kind,
        /** Degrees, percent or metres a second — whatever the kind measures. */
        public ?float $value = null,
        /** Wet, smoking, or open. Only for the kinds that are states. */
        public ?bool $triggered = null,
        /** Where it is, in the sensor's own words: a rack, a row, an aisle. */
        public ?string $location = null,
    ) {}
}
