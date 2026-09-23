<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

/**
 * Somebody answered.
 *
 * `fromStaff` decides who gets told, which is why it is on the event rather
 * than being looked up: a staff reply notifies the customer, a customer
 * reply notifies the assignee, and nobody is told about their own message.
 */
final readonly class TicketReplied
{
    public function __construct(
        public string $ticketId,
        public string $replyId,
        public string $organizationId,
        public bool $fromStaff,
        public ?string $correlationId = null,
    ) {}
}
