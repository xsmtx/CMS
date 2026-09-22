<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\GatewayCapabilities;
use App\Domain\Billing\GatewayEvent;
use App\Domain\Billing\PaymentIntent;
use App\Domain\Billing\PaymentResult;
use App\Domain\Billing\RefundRequest;
use App\Domain\Billing\RefundResult;
use App\Domain\Billing\WebhookRequest;

/**
 * Somewhere money can be taken.
 *
 * Four rules the implementations are held to, each with a test:
 *
 * 1. A redirect back to the site is never proof of payment. Only a verified
 *    webhook or a server-side confirmation moves money.
 * 2. A signature is verified before a payload is parsed.
 * 3. Events deduplicate on the provider's own event id.
 * 4. Outbound calls carry an idempotency key, so a retry cannot charge
 *    twice.
 *
 * Core knows no provider's vocabulary. An adapter translates.
 */
interface PaymentGateway
{
    /**
     * The key this gateway is configured and referenced under.
     */
    public function key(): string;

    public function capabilities(): GatewayCapabilities;

    public function createPayment(PaymentIntent $intent): PaymentResult;

    public function refund(RefundRequest $request): RefundResult;

    /**
     * Null when the request cannot be trusted: a bad signature, a payload
     * that does not parse, anything unexpected. A refusal, never a guess.
     */
    public function verifyWebhook(WebhookRequest $request): ?GatewayEvent;
}
