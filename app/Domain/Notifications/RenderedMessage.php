<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Branding\Brand;

/**
 * A template with its placeholders filled in.
 *
 * Subject and body are already escaped and already in the recipient's
 * language. A channel's job is to move these bytes; deciding what they say
 * happened before it was called.
 *
 * The brand comes with it, because **who a message is from is part of the
 * message**. A channel that had to resolve it would be making an
 * authorization-shaped decision inside a mail driver, and a reseller's
 * customer would eventually get an email signed by the provider.
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
        public ?Brand $brand = null,
    ) {}

    /**
     * The same message, under a brand.
     *
     * Attached by `Notifier` once, after rendering and before any channel
     * sees it — so every channel gets the same answer and none of them has
     * to ask.
     */
    public function under(Brand $brand): self
    {
        return new self(
            $this->event,
            $this->subject,
            $this->body,
            $this->locale,
            $this->actionUrl,
            $this->actionLabel,
            $this->data,
            $brand,
        );
    }
}
