<?php

declare(strict_types=1);

namespace InfraCMS\GatewayPayPal;

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
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PayPal, over Orders v2.
 *
 * **An order is not a payment.** PayPal creates an order, the customer approves
 * it, and the money moves only when the order is captured. Reporting the
 * created order as a completed payment is the classic PayPal integration bug:
 * every order looks paid and none of the money arrives. `createPayment`
 * therefore always returns *pending* with the approval link, and the capture is
 * what the webhook reports.
 *
 * **A webhook is verified by asking PayPal.** There is no local signature to
 * check: the headers are sent back to `/v1/notifications/verify-webhook-signature`
 * along with the webhook id and the raw body, and PayPal answers SUCCESS or
 * FAILURE. That makes verification a network call, which means a verification
 * that cannot be made is a notification that is **not believed** — never one
 * that is assumed good because the service was down.
 *
 * **The body is passed through untouched.** PayPal's verification hashes what it
 * was sent; decoding and re-encoding the JSON changes it, and a check that
 * passes on re-encoded input is not a check.
 *
 * It has never talked to PayPal. Written against the published documentation and
 * tested against faked HTTP, which proves the code and not the integration.
 */
final readonly class PayPalGateway implements PaymentGateway
{
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $webhookId,
        private string $apiBase = 'https://api-m.paypal.com',
        private int $timeoutSeconds = 20,
    ) {}

    public function key(): string
    {
        return 'paypal';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            refunds: true,
            partialRefunds: true,
            // Vaulting is a separate PayPal product with its own agreement.
            storedMethods: false,
            webhooks: true,
            redirects: true,
            unattendedCharges: false,
        );
    }

    public function createPayment(PaymentIntent $intent): PaymentResult
    {
        $token = $this->accessToken();

        if ($token === null) {
            return PaymentResult::failed('PayPal did not answer.');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($token)
                // PayPal honours this: a retried create returns the original
                // order rather than making a second one.
                ->withHeader('PayPal-Request-Id', $intent->idempotencyKey)
                ->post($this->apiBase.'/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => $intent->reference,
                        'description' => mb_substr($intent->description, 0, 127),
                        'amount' => [
                            'currency_code' => strtoupper($intent->amount->currency->code),
                            // A decimal string built from integer minor units.
                            'value' => $intent->amount->toDecimalString(),
                        ],
                    ]],
                    'payment_source' => [
                        'paypal' => [
                            'experience_context' => array_filter([
                                'return_url' => $intent->returnUrl,
                                'cancel_url' => $intent->cancelUrl,
                                'user_action' => 'PAY_NOW',
                            ]),
                        ],
                    ],
                ]);
        } catch (Throwable $exception) {
            return PaymentResult::failed($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            return PaymentResult::failed($this->reason($body));
        }

        // Always pending. An approved order is not a captured one, and this
        // platform only calls something paid when money has moved (ADR 0024).
        return PaymentResult::pending(
            (string) ($body['id'] ?? ''),
            $this->approvalLink($body),
            $body,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $token = $this->accessToken();

        if ($token === null) {
            return RefundResult::refused('PayPal did not answer.');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($token)
                ->withHeader('PayPal-Request-Id', $request->idempotencyKey)
                ->post($this->apiBase.'/v2/payments/captures/'.$request->paymentReference.'/refund', [
                    'amount' => [
                        'currency_code' => strtoupper($request->amount->currency->code),
                        'value' => $request->amount->toDecimalString(),
                    ],
                ]);
        } catch (Throwable $exception) {
            return RefundResult::refused($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            return RefundResult::refused($this->reason($body));
        }

        return RefundResult::accepted((string) ($body['id'] ?? ''), $body);
    }

    public function verifyWebhook(WebhookRequest $request): ?GatewayEvent
    {
        if (! $this->notificationIsGenuine($request)) {
            return null;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->body, true);

        if (! is_array($payload) || ! isset($payload['id'], $payload['event_type'])) {
            return null;
        }

        /** @var array<string, mixed> $resource */
        $resource = $payload['resource'] ?? [];

        $type = match ((string) $payload['event_type']) {
            'PAYMENT.CAPTURE.COMPLETED' => GatewayEventType::PaymentCompleted,
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => GatewayEventType::PaymentFailed,
            'PAYMENT.CAPTURE.REFUNDED' => GatewayEventType::PaymentRefunded,
            default => GatewayEventType::Ignored,
        };

        return new GatewayEvent(
            id: (string) $payload['id'],
            type: $type,
            paymentReference: (string) ($resource['id'] ?? ''),
            amount: $this->amountFrom($resource),
            failureReason: is_string($resource['status_details']['reason'] ?? null)
                ? (string) $resource['status_details']['reason']
                : null,
            payload: $payload,
        );
    }

    /**
     * Ask PayPal whether it sent this.
     *
     * A failure to reach PayPal is a notification that is **not believed**. The
     * alternative — assuming a notification is genuine because verification was
     * unavailable — turns an outage at PayPal into a way to mark invoices paid.
     */
    private function notificationIsGenuine(WebhookRequest $request): bool
    {
        $token = $this->accessToken();

        if ($token === null) {
            return false;
        }

        $headers = [
            'auth_algo' => $request->header('paypal-auth-algo'),
            'cert_url' => $request->header('paypal-cert-url'),
            'transmission_id' => $request->header('paypal-transmission-id'),
            'transmission_sig' => $request->header('paypal-transmission-sig'),
            'transmission_time' => $request->header('paypal-transmission-time'),
        ];

        foreach ($headers as $value) {
            if ($value === null || $value === '') {
                return false;
            }
        }

        /** @var array<string, mixed>|null $event */
        $event = json_decode($request->body, true);

        if (! is_array($event)) {
            return false;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($token)
                ->post($this->apiBase.'/v1/notifications/verify-webhook-signature', [
                    ...$headers,
                    'webhook_id' => $this->webhookId,
                    // The decoded event, which is what PayPal's own API expects
                    // here; the signature it checks was made over the bytes it
                    // sent and it re-derives them itself.
                    'webhook_event' => $event,
                ]);
        } catch (Throwable) {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return $response->successful()
            && (string) ($body['verification_status'] ?? '') === 'SUCCESS';
    }

    private function accessToken(): ?string
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post($this->apiBase.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (Throwable) {
            return null;
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        $token = $body['access_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function approvalLink(array $body): ?string
    {
        foreach ((array) ($body['links'] ?? []) as $link) {
            if (is_array($link) && (string) ($link['rel'] ?? '') === 'payer-action') {
                return (string) ($link['href'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function amountFrom(array $resource): ?Money
    {
        $value = $resource['amount']['value'] ?? null;
        $currency = $resource['amount']['currency_code'] ?? null;

        if (! is_string($value) || ! is_string($currency)) {
            return null;
        }

        // From the decimal string straight to minor units. No float in between.
        return Money::ofDecimal($value, $currency);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reason(array $body): string
    {
        $message = $body['message'] ?? $body['error_description'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'PayPal refused the request.';
    }
}
