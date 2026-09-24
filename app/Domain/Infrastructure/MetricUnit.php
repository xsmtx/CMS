<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * What a number means, and how to get it into the one form this platform keeps.
 *
 * Every dimension has exactly one canonical unit and the rest are inputs. Bytes,
 * not megabytes; bits per second, not megabits; a ratio between zero and one,
 * not a percentage. The reason is the same one the money rule has: a single
 * representation means nothing has to be converted at display time, and two
 * screens cannot disagree about whether 0.5 is half or fifty.
 *
 * Sources disagree wildly. Prometheus exports a CPU counter in seconds and a
 * utilisation in a ratio; Zabbix's `system.cpu.util` is a percentage; SNMP
 * gives octets; a vendor API gives megabytes and calls the field `size`. An
 * adapter declares which of these it is handing over and core does the
 * arithmetic — once, here, rather than once per adapter with a different rounding
 * mistake in each.
 *
 * `canonical()` is what makes a wrong unit a refusal rather than a silent
 * hundredfold error: a sample offering bytes for a CPU ratio is not a
 * conversion, it is a mistake, and the normalizer says so.
 */
enum MetricUnit: string
{
    // Dimensionless.
    case Ratio = 'ratio';
    case Percent = 'percent';

    // Data.
    case Bytes = 'bytes';
    case Kilobytes = 'kilobytes';
    case Megabytes = 'megabytes';
    case Gigabytes = 'gigabytes';
    case Terabytes = 'terabytes';

    // Throughput.
    case BitsPerSecond = 'bits_per_second';
    case KilobitsPerSecond = 'kilobits_per_second';
    case MegabitsPerSecond = 'megabits_per_second';
    case GigabitsPerSecond = 'gigabits_per_second';
    case BytesPerSecond = 'bytes_per_second';

    // Time.
    case Seconds = 'seconds';
    case Milliseconds = 'milliseconds';
    case Minutes = 'minutes';
    case Hours = 'hours';

    // Rate and count.
    case Count = 'count';
    case PerSecond = 'per_second';

    // Physical.
    case Celsius = 'celsius';
    case Fahrenheit = 'fahrenheit';
    case Watts = 'watts';
    case Kilowatts = 'kilowatts';

    /**
     * The one unit of this dimension that gets stored.
     */
    public function canonical(): self
    {
        return match ($this) {
            self::Ratio, self::Percent => self::Ratio,
            self::Bytes, self::Kilobytes, self::Megabytes,
            self::Gigabytes, self::Terabytes => self::Bytes,
            self::BitsPerSecond, self::KilobitsPerSecond, self::MegabitsPerSecond,
            self::GigabitsPerSecond, self::BytesPerSecond => self::BitsPerSecond,
            self::Seconds, self::Milliseconds, self::Minutes, self::Hours => self::Seconds,
            self::Count => self::Count,
            self::PerSecond => self::PerSecond,
            self::Celsius, self::Fahrenheit => self::Celsius,
            self::Watts, self::Kilowatts => self::Watts,
        };
    }

    public function isCanonical(): bool
    {
        return $this->canonical() === $this;
    }

    /**
     * The same measurement in this dimension's canonical unit.
     *
     * Decimal multiples rather than binary ones for storage — a provider
     * invoicing for a terabyte means 10^12, and disagreeing with the invoice by
     * ten per cent is worse than disagreeing with `du`.
     *
     * Fahrenheit is the one member that is not a multiplication, which is why
     * this is a `match` returning a value rather than a factor table.
     */
    public function toCanonical(float $value): float
    {
        return match ($this) {
            self::Ratio, self::Bytes, self::BitsPerSecond,
            self::Seconds, self::Count, self::PerSecond,
            self::Celsius, self::Watts => $value,

            self::Percent => $value / 100,

            self::Kilobytes => $value * 1_000,
            self::Megabytes => $value * 1_000_000,
            self::Gigabytes => $value * 1_000_000_000,
            self::Terabytes => $value * 1_000_000_000_000,

            self::KilobitsPerSecond => $value * 1_000,
            self::MegabitsPerSecond => $value * 1_000_000,
            self::GigabitsPerSecond => $value * 1_000_000_000,
            self::BytesPerSecond => $value * 8,

            self::Milliseconds => $value / 1_000,
            self::Minutes => $value * 60,
            self::Hours => $value * 3_600,

            self::Fahrenheit => ($value - 32) * 5 / 9,
            self::Kilowatts => $value * 1_000,
        };
    }

    public function labelKey(): string
    {
        return 'infrastructure.units.'.$this->value;
    }
}
