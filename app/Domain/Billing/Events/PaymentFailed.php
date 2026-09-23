<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

final readonly class PaymentFailed
{
    public function __construct(
        public string $paymentId,
        public ?string $invoiceId,
        public string $organizationId,
        public ?string $reason = null,
        public ?string $correlationId = null,
    ) {}
}
