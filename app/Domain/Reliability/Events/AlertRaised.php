<?php

declare(strict_types=1);

namespace App\Domain\Reliability\Events;

/**
 * A machine observation became visible to people.
 *
 * Raised the moment an alert reaches `Raised` — the first evaluation for a
 * rule with no `for_minutes`, or the one that finds it has been bad for long
 * enough. Never on a repeat: a disk that crosses 90% every minute for six
 * hours is one alert, and one message.
 *
 * It carries identifiers rather than the model (ADR 0027), and nothing
 * notifies from the evaluator itself — `Notifier` is the one place a message
 * leaves this platform, and a sweep that sent its own mail would be a second
 * place that knows about opt-outs, locales and delivery records.
 */
final readonly class AlertRaised
{
    public function __construct(
        public string $alertId,
        public string $organizationId,
        /**
         * The window that held the notification, if one did. Carried on the
         * event as well as written to the row so a listener does not have to
         * ask the clock a second time and get a different answer.
         */
        public ?string $suppressedBy = null,
    ) {}
}
