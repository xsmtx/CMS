<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Domain\Infrastructure\MetricKind;
use App\Domain\Infrastructure\MetricUnit;

/**
 * One service using disproportionately more of one thing than its neighbours.
 *
 * The median is carried as well as the reading, because the whole claim this
 * row makes is a comparison: "94% where the middle neighbour is 4%" is an
 * answer somebody can check, and "94%" on its own is a number with no
 * argument behind it.
 */
final readonly class NoisyRow
{
    public function __construct(
        public string $nodeKey,
        public string $nodeLabel,
        public string $serviceKey,
        public string $serviceLabel,
        public ?string $serviceId,
        public MetricKind $metric,
        public MetricUnit $unit,
        public float $value,
        public float $median,
        /**
         * How many times the median this is — or **null when the median is
         * zero**, which is not a failure and is the most common shape on a
         * shared host.
         *
         * Thirty-nine idle sites and one busy one has a median of nought, and
         * a multiple of it is undefined rather than infinite. The row says so
         * in words instead of printing a number that is not one; there is
         * deliberately no floor under which a reading is called insignificant,
         * because 0.1% of a 128-core machine is not nothing and core has no
         * way to know what is.
         */
        public ?float $times,
        public int $neighbours,
    ) {}
}
