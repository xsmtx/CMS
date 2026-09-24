<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Carbon\CarbonImmutable;

/**
 * How often a document sequence starts again at one.
 *
 * Not a preference. An unbroken sequence from INV-000001 forever is not an
 * acceptable invoice book in Turkey, Italy, Spain, Portugal or Poland, all of
 * which expect the numbering to restart with the financial year; an installation
 * that cannot express that cannot legally be used there. `Never` stays the
 * default because it is what every existing sequence already does, and a
 * migration that silently reset somebody's invoice numbers would be far worse
 * than one that changed nothing.
 *
 * The period is a **key that is compared**, not an elapsed time. `keyFor()`
 * answers "which period is this date in", the sequence remembers which period
 * its current value belongs to, and a mismatch is what resets it. Anything
 * based on how long ago the row was last touched cannot tell "nobody invoiced
 * in January" from "already reset in January", and the second one must not
 * reset twice.
 */
enum NumberResetPeriod: string
{
    case Never = 'never';
    case Yearly = 'yearly';
    case Monthly = 'monthly';

    /**
     * Which period a date falls in, or null when the sequence never restarts.
     *
     * The year alone for a yearly reset, so a sequence written in 2026 and read
     * in 2027 mismatches on the first allocation of the new year and on none
     * after it.
     */
    public function keyFor(CarbonImmutable $date): ?string
    {
        return match ($this) {
            self::Never => null,
            self::Yearly => $date->format('Y'),
            self::Monthly => $date->format('Y-m'),
        };
    }

    public function labelKey(): string
    {
        return 'billing.settings.numbering.periods.'.$this->value;
    }
}
