<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Shared\Money;

/**
 * A request to take money.
 *
 * Carries an idempotency key that the adapter passes to the provider, so a
 * retried request — ours or theirs — cannot charge a customer twice.
 */
final readonly class PaymentIntent
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $idempotencyKey,
        public Money $amount,
        public string $reference,
        public string $description,
        public ?string $customerEmail = null,
        public ?string $customerName = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public ?string $storedMethodToken = null,
        public array $metadata = [],
    ) {}
}
