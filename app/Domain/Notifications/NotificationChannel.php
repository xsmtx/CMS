<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

enum NotificationChannel: string
{
    case Mail = 'mail';

    /** In-app: a row the recipient sees next time they sign in. */
    case Database = 'database';

    /** An operator's endpoint, for a system that wants to know. */
    case Webhook = 'webhook';

    public function labelKey(): string
    {
        return 'notifications.channels.'.$this->value;
    }

    /**
     * Whether a recipient needs an address for this channel to work.
     */
    public function needsAddress(): bool
    {
        return $this === self::Mail;
    }
}
