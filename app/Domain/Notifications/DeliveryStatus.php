<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * What became of one message on one channel.
 *
 * `Suppressed` is separate from `Failed` on purpose. "We did not send this
 * because they asked us not to" and "we tried and it bounced" are different
 * answers to the same question, and a support agent reading the log needs
 * to tell them apart at a glance.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    /** Not sent: the recipient opted out, or had no address. */
    case Suppressed = 'suppressed';

    public function labelKey(): string
    {
        return 'notifications.delivery_statuses.'.$this->value;
    }
}
