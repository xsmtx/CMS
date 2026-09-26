<?php

declare(strict_types=1);

namespace App\Domain\Reliability;

/**
 * Whether the thing an alert is about is still true.
 *
 * **There is no `acknowledged`.** An alert is a machine observation and
 * acknowledging one is the ritual that teaches people to click without
 * reading: four hundred disk warnings a week, each needing a press, and within
 * a month nobody reads any of them. The human act in this product is opening
 * an **incident**, which has a state somebody moves and a timeline somebody
 * writes — and an alert that never became an incident is an alert nobody
 * thought was worth one, which is a true and useful thing for the list to say.
 *
 * `Suppressed` is separate from cleared and it is not silence: the observation
 * was made, recorded and is visible, and only the notification was held back
 * because a maintenance window was open. An operator asking "did anything
 * happen during the maintenance" must get the true answer.
 */
enum AlertState: string
{
    case Raised = 'raised';
    case Suppressed = 'suppressed';
    case Cleared = 'cleared';

    public function isOpen(): bool
    {
        return $this !== self::Cleared;
    }

    public function labelKey(): string
    {
        return 'reliability.alert_states.'.$this->value;
    }
}
