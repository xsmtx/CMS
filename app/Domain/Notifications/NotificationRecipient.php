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
    ) {}

    public function isAddressable(NotificationChannel $channel): bool
    {
        return ! $channel->needsAddress() || ($this->email !== null && $this->email !== '');
    }
}
