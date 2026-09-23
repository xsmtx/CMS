<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

/**
 * An invoice became a document.
 *
 * Carries identifiers, like every event here: a listener running later, or
 * on another worker, reads the row as it is now rather than as it was when
 * the event was made.
 */
final readonly class InvoiceIssued
{
    public function __construct(
        public string $invoiceId,
        public string $organizationId,
        public ?string $correlationId = null,
    ) {}
}
