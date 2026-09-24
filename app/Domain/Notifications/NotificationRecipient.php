<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Somebody who should be told, and how to reach them.
 *
 * A value object rather than a model, so a channel cannot reach through a
 * recipient into the database, and so a staff member, a contact and a bare
 * webhook endpoint can all be addressed the same way.
 */
final readonly class NotificationRecipient
{
    /**
     * @param  class-string|null  $subjectType  For the in-app row.
     */
    public function __construct(
        public string $name,
        public ?string $email,
        public string $locale = 'en',
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public bool $isStaff = false,
        public bool $acceptsCategory = true,
        /**
         * For SMS. Added with a default rather than in place of the email,
         * because a person has both and a channel picks the one it needs.
         */
        public ?string $phone = null,
    ) {}

    /**
     * Whether this channel can reach this person at all.
     *
     * Per channel, not per recipient: an email address does not make somebody
     * reachable by text, and a number does not make them reachable by mail. A
     * single `email !== null` check answered the wrong question the moment a
     * second addressed channel existed.
     */
    public function isAddressable(NotificationChannel $channel): bool
    {
        if (! $channel->needsAddress()) {
            return true;
        }

        $address = $channel === NotificationChannel::Sms ? $this->phone : $this->email;

        return $address !== null && trim($address) !== '';
    }

    /**
     * The address this channel would use, or null when it needs none.
     */
    public function addressFor(NotificationChannel $channel): ?string
    {
        return match ($channel) {
            NotificationChannel::Mail => $this->email,
            NotificationChannel::Sms => $this->phone,
            default => null,
        };
    }
}
