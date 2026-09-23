<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * A template with its placeholders filled in.
 *
 * Subject and body are already escaped and already in the recipient's
 * language. A channel's job is to move these bytes; deciding what they say
 * happened before it was called.
 */
final readonly class RenderedMessage
{
    /**
     * @param  array<string, string>  $data  The values that were substituted.
     */
    public function __construct(
        public NotificationEvent $event,
        public string $subject,
        public string $body,
        public string $locale,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
        public array $data = [],
    ) {}
}
