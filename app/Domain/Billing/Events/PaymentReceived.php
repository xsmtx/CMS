<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

final readonly class PaymentReceived
{
    public function __construct(
        public string $paymentId,
        public string $invoiceId,
        public string $organizationId,
        public ?string $correlationId = null,
    ) {}
}
