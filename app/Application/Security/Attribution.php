<?php

declare(strict_types=1);

namespace App\Application\Security;

/**
 * Whose it was, at the moment in question.
 *
 * All three may be null, and that is a real answer rather than a failure: an
 * address in a range nobody recorded, a domain that is not ours, a sender who
 * is simply wrong. A report this platform cannot attribute is still a report
 * somebody has to answer, and the screen says so in words rather than drawing
 * a dash.
 */
final readonly class Attribution
{
    public function __construct(
        public ?string $customerId = null,
        public ?string $serviceId = null,
        public ?string $domainId = null,
    ) {}

    public static function unattributed(): self
    {
        return new self;
    }

    public function isAttributed(): bool
    {
        return $this->customerId !== null;
    }
}
