<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use Carbon\CarbonImmutable;

/**
 * Where one metric is heading, and how sure that is.
 *
 * `fullOn` is null when nothing is filling — a flat or falling line — and
 * that is an answer rather than a missing one: "this is not the disk to worry
 * about" is what an operator reading a list of forty resources needs most.
 *
 * `days` is carried because the honesty of the rest depends on it. Fourteen
 * points and ninety points give the same kind of line and deserve different
 * amounts of belief, and a screen that shows a date without saying what it
 * was drawn from is a screen that gets quoted in a meeting.
 */
final readonly class CapacityOutlook
{
    public function __construct(
        /** The latest daily average. */
        public float $current,
        /** What "full" means for this metric on this resource. */
        public float $ceiling,
        /** The slope, per day, in the metric's own unit. */
        public float $perDay,
        /** When it reaches the ceiling, or null when it is not heading there. */
        public ?CarbonImmutable $fullOn,
        /** How many daily points the line was drawn through. */
        public int $days,
    ) {}

    public function isFilling(): bool
    {
        return $this->fullOn !== null;
    }

    /**
     * How full it is now, as a ratio of the ceiling.
     */
    public function utilisation(): float
    {
        return $this->ceiling > 0.0 ? $this->current / $this->ceiling : 0.0;
    }

    /**
     * Days until the ceiling, or null when it is not heading there.
     */
    public function daysRemaining(?CarbonImmutable $from = null): ?int
    {
        if ($this->fullOn === null) {
            return null;
        }

        $start = $from ?? CarbonImmutable::now();

        return max(0, (int) $start->startOfDay()->diffInDays($this->fullOn->startOfDay(), false));
    }
}
