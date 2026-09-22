<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Gateways;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayCapabilities;
use App\Domain\Billing\GatewayEvent;
use App\Domain\Billing\PaymentIntent;
use App\Domain\Billing\PaymentResult;
use App\Domain\Billing\RefundRequest;
use App\Domain\Billing\RefundResult;
use App\Domain\Billing\WebhookRequest;

/**
 * Bank transfer: the customer sends money and an operator says it arrived.
 *
 * A real gateway rather than a special case in the billing code, so
 * "recording a transfer" and "a card succeeded" travel the same path and
 * produce the same ledger rows. Every installation has this one, and a
 * platform that cannot take a bank transfer cannot be sold in half the
 * world.
 *
 * It creates nothing and confirms nothing: `createPayment` answers pending
 * and the money is recorded by hand when it lands.
 */
final readonly class ManualGateway implements PaymentGateway
{
    public function __construct(private string $instructions = '') {}

    public function key(): string
    {
        return 'manual';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            // An operator sends the money back through their bank and
            // records it here. The platform cannot do it for them.
            refunds: false,
            partialRefunds: false,
            storedMethods: false,
            webhooks: false,
            redirects: false,
            unattendedCharges: false,
        );
    }

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function createPayment(PaymentIntent $intent): PaymentResult
    {
        // Nothing has happened. The customer now owes a transfer, and the
        // invoice stays unpaid until somebody sees it arrive.
        return PaymentResult::pending($intent->reference);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return RefundResult::refused(__('billing.errors.refund_not_supported'));
    }

    public function verifyWebhook(WebhookRequest $request): ?GatewayEvent
    {
        // A bank does not call us back. Anything arriving here claiming to
        // be from this gateway is not.
        return null;
    }
}
