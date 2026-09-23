<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * How fast this needs answering.
 *
 * Priority moves the clock, not just the sort order. A department's SLA is
 * stated for normal priority and scaled from there, so an operator sets two
 * numbers rather than eight.
 */
enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function labelKey(): string
    {
        return 'support.priorities.'.$this->value;
    }

    /**
     * What the department's stated SLA is multiplied by.
     */
    public function slaFactor(): float
    {
        return match ($this) {
            self::Low => 2.0,
            self::Normal => 1.0,
            self::High => 0.5,
            self::Urgent => 0.25,
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 4,
            self::High => 3,
            self::Normal => 2,
            self::Low => 1,
        };
    }
}
