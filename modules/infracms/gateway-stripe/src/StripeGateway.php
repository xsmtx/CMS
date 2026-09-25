<?php

declare(strict_types=1);

namespace InfraCMS\GatewayStripe;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayCapabilities;
use App\Domain\Billing\GatewayEvent;
use App\Domain\Billing\GatewayEventType;
use App\Domain\Billing\PaymentIntent;
use App\Domain\Billing\PaymentResult;
use App\Domain\Billing\RefundRequest;
use App\Domain\Billing\RefundResult;
use App\Domain\Billing\WebhookRequest;
use App\Domain\Shared\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Stripe, over its HTTP API.
 *
 * Written against the API directly rather than the SDK: the SDK pulls a
 * large dependency into a platform that already has an HTTP client with
 * timeouts, retries and correlation-id propagation configured, and the
 * three endpoints used here are stable.
 *
 * Money crosses the boundary as minor units, which is what Stripe uses —
 * no conversion, no rounding, nothing to get wrong.
 */
final readonly class StripeGateway implements PaymentGateway
{
    public function __construct(
        private string $secret,
        private string $webhookSecret,
        private string $apiBase = 'https://api.stripe.com',
        private int $timeoutSeconds = 15,
        private int $signatureTolerance = 300,
    ) {}

    public function key(): string
    {
        return 'stripe';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            refunds: true,
            partialRefunds: true,
            storedMethods: true,
            webhooks: true,
            redirects: true,
            unattendedCharges: true,
        );
    }

    public function createPayment(PaymentIntent $intent): PaymentResult
    {
        try {
            $response = $this->client()
                // Stripe honours this header: a retried request returns the
                // original intent rather than charging again.
                ->withHeader('Idempotency-Key', $intent->idempotencyKey)
                ->asForm()
                ->post($this->apiBase.'/v1/payment_intents', array_filter([
                    'amount' => $intent->amount->minorUnits,
                    'currency' => strtolower($intent->amount->currency->code),
                    'description' => $intent->description,
                    'receipt_email' => $intent->customerEmail,
                    'payment_method' => $intent->storedMethodToken,
                    'confirm' => $intent->storedMethodToken === null ? null : 'true',
                    'metadata[reference]' => $intent->reference,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (Throwable $exception) {
            // A network failure is not a decline. Nothing is marked paid
            // and the message says what to do next.
            return PaymentResult::failed($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            return PaymentResult::failed($this->errorMessage($body));
        }

        $status = (string) ($body['status'] ?? '');
        $reference = (string) ($body['id'] ?? '');

        if ($status === 'succeeded') {
            return PaymentResult::completed($reference, $body);
        }

        // Anything else is unfinished: the customer has somewhere to go, or
        // the bank has something to ask. The webhook decides, not us.
        return PaymentResult::pending(
            $reference,
            $this->redirectFrom($body),
            $body,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        try {
            $response = $this->client()
                ->withHeader('Idempotency-Key', $request->idempotencyKey)
                ->asForm()
                ->post($this->apiBase.'/v1/refunds', array_filter([
                    'payment_intent' => $request->paymentReference,
                    'amount' => $request->amount->minorUnits,
                    'reason' => $this->refundReason($request->reason),
                ], static fn (mixed $value): bool => $value !== null));
        } catch (Throwable $exception) {
            return RefundResult::refused($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            return RefundResult::refused($this->errorMessage($body));
        }

        return RefundResult::accepted((string) ($body['id'] ?? ''), $body);
    }

    /**
     * Verify first, parse second.
     *
     * A payload is not read at all until its signature checks out, and the
     * signature is computed over the exact bytes received — decoding and
     * re-encoding the JSON would change them, and a verification that
     * passes on re-encoded input is not a verification.
     */
    public function verifyWebhook(WebhookRequest $request): ?GatewayEvent
    {
        if (! $this->signatureIsValid($request)) {
            return null;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->body, true);

        if (! is_array($payload) || ! isset($payload['id'], $payload['type'])) {
            return null;
        }

        /** @var array<string, mixed> $object */
        $object = $payload['data']['object'] ?? [];

        $type = match ((string) $payload['type']) {
            'payment_intent.succeeded', 'charge.succeeded' => GatewayEventType::PaymentCompleted,
            'payment_intent.payment_failed', 'charge.failed' => GatewayEventType::PaymentFailed,
            'charge.refunded' => GatewayEventType::PaymentRefunded,
            default => GatewayEventType::Ignored,
        };

        return new GatewayEvent(
            id: (string) $payload['id'],
            type: $type,
            paymentReference: $this->referenceFrom($object),
            amount: $this->amountFrom($object),
            failureReason: isset($object['last_payment_error']['message'])
                ? (string) $object['last_payment_error']['message']
                : null,
            payload: $payload,
        );
    }

    /**
     * Stripe's scheme: a timestamp and one or more v1 signatures over
     * "timestamp.body", compared in constant time.
     */
    private function signatureIsValid(WebhookRequest $request): bool
    {
        $header = $request->header('stripe-signature');

        if ($header === null || $this->webhookSecret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        // A replayed payload from last month has a valid signature. The
        // tolerance is what stops it being accepted.
        if (abs(time() - (int) $timestamp) > $this->signatureTolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->body, $this->webhookSecret);

        return array_any($signatures, fn ($signature): bool => hash_equals($expected, $signature));
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function referenceFrom(array $object): ?string
    {
        foreach (['payment_intent', 'id'] as $key) {
            if (isset($object[$key]) && is_string($object[$key])) {
                return $object[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function amountFrom(array $object): ?Money
    {
        $amount = $object['amount_received'] ?? $object['amount'] ?? null;
        $currency = $object['currency'] ?? null;

        if (! is_numeric($amount) || ! is_string($currency)) {
            return null;
        }

        return Money::ofMinor((int) $amount, strtoupper($currency));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function redirectFrom(array $body): ?string
    {
        $url = $body['next_action']['redirect_to_url']['url'] ?? null;

        return is_string($url) ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorMessage(array $body): string
    {
        $message = $body['error']['message'] ?? null;

        return is_string($message) ? $message : (string) __('billing.errors.payment_failed');
    }

    /**
     * Stripe accepts three reasons and rejects anything else, so an
     * operator's own words go in the metadata rather than the field.
     */
    private function refundReason(?string $reason): ?string
    {
        return $reason === null ? null : 'requested_by_customer';
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->secret)
            ->timeout($this->timeoutSeconds)
            ->retry(2, 200, throw: false);
    }
}
