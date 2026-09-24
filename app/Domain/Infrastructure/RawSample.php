<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use Carbon\CarbonImmutable;

/**
 * A reading as the source phrased it.
 *
 * An adapter hands these over without knowing what this platform calls things.
 * That is the division of labour the normalizer exists for: an adapter author
 * knows the vendor's field names and units, and should not also have to learn a
 * canonical vocabulary in order to contribute one metric.
 *
 * `unit` is a string rather than a `MetricUnit` because an adapter may be
 * reporting whatever a device's MIB said. An unrecognised unit is a refusal with
 * a reason, not a guess — guessing between bytes and megabytes is a factor of a
 * million, and the screen would look plausible either way.
 *
 * `target` is a node's `node_key`, not its id. An adapter has never heard of this
 * platform's identifiers and should not be handed any: it knows a hostname, a
 * serial number or a device id, which is exactly what a node key is.
 */
final readonly class RawSample
{
    public function __construct(
        public string $target,
        public string $name,
        public float $value,
        public ?string $unit = null,
        public ?CarbonImmutable $sampledAt = null,
        /**
         * How long this reading stays meaningful, in seconds.
         *
         * The adapter knows its own polling interval and core does not. A value
         * five minutes old from a poller that runs every minute is a problem; the
         * same value from a poller that runs hourly is fine. Without this the
         * Telemetry screen would have to invent one number for everything and be
         * wrong about most of it.
         */
        public ?int $staleAfterSeconds = null,
    ) {}
}
