<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * What this installation can say something through.
 *
 * **Closed on purpose**, unlike `ResourceKind` which is open (ADR 0043). Core
 * has to guarantee what a channel *means* — whether it needs an address, whether
 * opting out of it is a customer's choice, what a delivery row records — and a
 * vocabulary anybody could extend would be a vocabulary core could not reason
 * about. A module supplies the *provider*; the channel is core's word.
 *
 * `Sms` and `Chat` were added when the first modules for them were written, and
 * the reason they had to be added rather than improvised is worth keeping: a
 * module cannot say "I am SMS" if there is no such thing, and three chat
 * providers registering as `Webhook` would each overwrite the last — and take
 * core's own webhook channel with them.
 */
enum NotificationChannel: string
{
    case Mail = 'mail';

    /** In-app: a row the recipient sees next time they sign in. */
    case Database = 'database';

    /** An operator's endpoint, for a system that wants to know. */
    case Webhook = 'webhook';

    /** A text message, to a person, at a number. */
    case Sms = 'sms';

    /**
     * A room somebody watches: Slack, Discord, Mattermost.
     *
     * Addressed like a webhook rather than like a person — the endpoint belongs
     * to the operator, not to the recipient — which is why it needs no address
     * on the recipient.
     */
    case Chat = 'chat';

    public function labelKey(): string
    {
        return 'notifications.channels.'.$this->value;
    }

    /**
     * Whether a recipient needs an address of their own for this to work.
     *
     * Mail needs an email and SMS needs a number. A webhook and a chat room are
     * the operator's endpoints and are the same for everybody; an in-app row is
     * addressed by the subject already on the recipient.
     */
    public function needsAddress(): bool
    {
        return $this === self::Mail || $this === self::Sms;
    }
}
