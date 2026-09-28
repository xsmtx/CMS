<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Power;

/**
 * What an environmental sensor measures (§11).
 *
 * **Three of these are numbers and three are not**, which is the distinction
 * that decides where each one goes. Temperature, humidity and airflow are
 * readings and become telemetry; a leak, smoke or a door is a state, and a
 * state stored as 1.0 is a chart nobody can read and an alert nobody can
 * word.
 *
 * So `isReading()` is not a convenience — it is the rule `RecordSamples`
 * needs, and the reason a door sensor never reaches `resource_metrics`.
 */
enum SensorKind: string
{
    case Temperature = 'temperature';
    case Humidity = 'humidity';
    case Airflow = 'airflow';

    case Leak = 'leak';
    case Smoke = 'smoke';
    case Door = 'door';

    /** Whether this one is a number rather than a state. */
    public function isReading(): bool
    {
        return match ($this) {
            self::Temperature, self::Humidity, self::Airflow => true,
            self::Leak, self::Smoke, self::Door => false,
        };
    }

    public function labelKey(): string
    {
        return 'dcim.power.sensors.'.$this->value;
    }
}
