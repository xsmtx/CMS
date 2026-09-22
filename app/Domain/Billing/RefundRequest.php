<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Shared\Money;

/**
 * A request to send money back.
 */
final readonly class RefundRequest
{
    public function __construct(
        public string $idempotencyKey,
        public string $paymentReference,
        public Money $amount,
        public ?string $reason = null,
    ) {}
}
