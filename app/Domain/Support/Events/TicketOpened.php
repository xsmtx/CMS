<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

final readonly class TicketOpened
{
    public function __construct(
        public string $ticketId,
        public string $organizationId,
        public ?string $correlationId = null,
    ) {}
}
