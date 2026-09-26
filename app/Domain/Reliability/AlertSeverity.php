<?php

declare(strict_types=1);

namespace App\Domain\Reliability;

/**
 * How loudly to say it.
 *
 * Three, and deliberately not five. Every alerting system that offers
 * `info/low/medium/high/critical` ends up with everything set to medium,
 * because the difference between low and medium is a conversation nobody
 * wants to have twice — and a scale nobody distinguishes is a scale that
 * sorts alphabetically.
 *
 * The three here are distinguished by **what a person does about them**,
 * which is a question with an answer:
 *
 * - `Warning` — look at it during the day. Nobody is woken.
 * - `Critical` — somebody is told now, and it is expected to be acted on.
 * - `Emergency` — customers are already affected; it is an incident before
 *   anybody has decided that it is.
 *
 * The mapping to notification urgency lives in `Notifier`'s vocabulary rather
 * than here: this enum says how bad, and how bad is not the same question as
 * by which channel.
 */
enum AlertSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';
    case Emergency = 'emergency';

    /**
     * Whether somebody is expected to be interrupted.
     *
     * Used to decide whether a raise notifies at all. A warning that woke
     * somebody would be a warning they turn off.
     */
    public function interrupts(): bool
    {
        return $this !== self::Warning;
    }

    /**
     * The tone `AppStatus` draws it in.
     *
     * A method rather than a map in a Vue file: the status vocabulary is
     * `resources/js/status.ts` and every one of these words is already in it,
     * so the browser needs the value and nothing else.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Warning => 'warning',
            self::Critical, self::Emergency => 'critical',
        };
    }

    public function labelKey(): string
    {
        return 'reliability.severities.'.$this->value;
    }
}
