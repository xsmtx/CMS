<?php

declare(strict_types=1);

namespace App\Domain\Reliability;

/**
 * Which way round a threshold is read.
 *
 * Two members, and the reason there are not six is `MetricKind::higherIsWorse()`:
 * the platform already knows that a high CPU utilisation is bad and a low cache
 * hit ratio is bad, so a rule says "above" or "below" and nothing has to also
 * encode "and that is the bad direction". An operator writing a rule on disk
 * usage picks `Above 90`; one writing a rule on battery charge picks `Below 20`;
 * neither has to think about it twice.
 *
 * `AtLeast`/`AtMost` are absent on purpose. The difference between `> 90` and
 * `>= 90` has never mattered to anybody at three in the morning, and offering
 * both is offering a choice whose consequence nobody can state.
 */
enum AlertComparison: string
{
    case Above = 'above';
    case Below = 'below';

    public function matches(float $value, float $threshold): bool
    {
        return match ($this) {
            self::Above => $value > $threshold,
            self::Below => $value < $threshold,
        };
    }

    public function labelKey(): string
    {
        return 'reliability.comparisons.'.$this->value;
    }
}
