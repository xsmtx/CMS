<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Shared\Money;

/**
 * A verified thing that happened at a gateway.
 *
 * `id` is the provider's own event identifier and is what deduplication
 * keys on: a gateway that delivers the same event five times has to produce
 * one payment.
 */
final readonly class GatewayEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public GatewayEventType $type,
        public ?string $paymentReference = null,
        public ?Money $amount = null,
        public ?string $failureReason = null,
        public array $payload = [],
    ) {}

    public function isActionable(): bool
    {
        return $this->type !== GatewayEventType::Ignored;
    }
}
