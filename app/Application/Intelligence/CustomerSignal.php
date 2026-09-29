<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Application\Reports\MoneyByCurrency;
use App\Domain\Intelligence\HealthSignal;

/**
 * One signal, with the arithmetic that produced it (§21).
 *
 * `count` is how many things, `money` is how much where the signal is about
 * money, and `days` is how long where the signal is about time. All three
 * are separate on purpose: a signal that folded them into one "severity"
 * would be the score this whole family refuses to compute.
 */
final readonly class CustomerSignal
{
    public function __construct(
        public HealthSignal $signal,
        public int $count,
        public ?MoneyByCurrency $money = null,
        /** How long the worst one has been true. */
        public ?int $days = null,
    ) {}
}
