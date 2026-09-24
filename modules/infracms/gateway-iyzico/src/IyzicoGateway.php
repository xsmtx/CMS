<?php

declare(strict_types=1);

namespace InfraCMS\GatewayIyzico;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayCapabilities;
use App\Domain\Billing\GatewayEvent;
use App\Domain\Billing\GatewayEventType;
use App\Domain\Billing\PaymentIntent;
use App\Domain\Billing\PaymentResult;
use App\Domain\Billing\RefundRequest;
use App\Domain\Billing\RefundResult;
use App\Domain\Billing\WebhookRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * İyzico, over its Checkout Form API.
 *
 * **Authentication is a signature, not a bearer token**, and the signature
 * covers the request body. That has a consequence worth stating: the bytes
 * signed must be the bytes sent, so the payload is encoded once into a string
 * and that same string is both hashed and posted. Encoding it twice — once to
 * sign and once to send — is how a signature passes in a test and fails against
 * the real service, because two encoders disagree about a slash or a unicode
 * escape.
 *
 * **Amounts are decimal strings, not minor units.** İyzico is one of the few
 * APIs that insists on `"12.34"`. `Money::toDecimalString()` produces it from
 * integer minor units without a float ever existing, which is the only safe way
 * to cross that boundary.
 *
 * **There is no webhook in the usual sense.** İyzico posts back to the return
 * URL with a token, and the truth is fetched by asking İyzico about that token —
 * a server-to-server answer, which is what ADR 0024 requires before money moves.
 * `verifyWebhook` therefore *retrieves* rather than verifying a signature.
 *
 * It has never talked to İyzico. Written against the published documentation and
 * tested against faked HTTP, which proves the code and not the integration.
 */
final readonly class IyzicoGateway implements PaymentGateway
{
    public function __construct(
        private string $apiKey,
        private string $secretKey,
        private string $apiBase = 'https://api.iyzipay.com',
        private int $timeoutSeconds = 20,
    ) {}

    public function key(): string
    {
        return 'iyzico';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            refunds: true,
            partialRefunds: true,
            // İyzico can store a card, but only through its own card-storage
            // product with its own agreement. Until that is configured this
            // says no, because a capability nobody enabled is a renewal that
            // silently fails.
            storedMethods: false,
            webhooks: true,
            redirects: true,
            unattendedCharges: false,
        );
    }

    public function createPayment(PaymentIntent $intent): PaymentResult
    {
        $body = [
            'locale' => 'tr',
            'conversationId' => $intent->reference,
            'price' => $intent->amount->toDecimalString(),
            'paidPrice' => $intent->amount->toDecimalString(),
            'currency' => strtoupper($intent->amount->currency->code),
            'basketId' => $intent->reference,
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => $intent->returnUrl ?? '',
            'buyer' => [
                'id' => (string) ($intent->metadata['customer_id'] ?? $intent->reference),
                'name' => $this->firstName($intent->customerName),
                'surname' => $this->lastName($intent->customerName),
                'email' => $intent->customerEmail ?? '',
                'identityNumber' => (string) ($intent->metadata['identity_number'] ?? '11111111111'),
                'registrationAddress' => (string) ($intent->metadata['address'] ?? '-'),
                'city' => (string) ($intent->metadata['city'] ?? '-'),
                'country' => (string) ($intent->metadata['country'] ?? 'Turkey'),
                'ip' => (string) ($intent->metadata['ip'] ?? '0.0.0.0'),
            ],
            'basketItems' => [[
                'id' => $intent->reference,
                'name' => $intent->description,
                'category1' => 'Hosting',
                'itemType' => 'VIRTUAL',
                'price' => $intent->amount->toDecimalString(),
            ]],
        ];

        $response = $this->post('/payment/iyzipos/checkoutform/initialize/auth/ecom', $body);

        if ($response === null) {
            return PaymentResult::failed('İyzico did not answer.');
        }

        if ((string) ($response['status'] ?? '') !== 'success') {
            return PaymentResult::failed($this->reason($response));
        }

        // The token is what identifies this attempt to İyzico later, so it is
        // the reference rather than the conversation id we chose.
        return PaymentResult::pending(
            (string) ($response['token'] ?? $intent->reference),
            (string) ($response['paymentPageUrl'] ?? ''),
            $response,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->post('/payment/refund', [
            'locale' => 'tr',
            'conversationId' => $request->idempotencyKey,
            'paymentTransactionId' => $request->paymentReference,
            'price' => $request->amount->toDecimalString(),
            'currency' => strtoupper($request->amount->currency->code),
        ]);

        if ($response === null) {
            return RefundResult::refused('İyzico did not answer.');
        }

        if ((string) ($response['status'] ?? '') !== 'success') {
            return RefundResult::refused($this->reason($response));
        }

        return RefundResult::accepted((string) ($response['paymentId'] ?? ''), $response);
    }

    /**
     * Ask İyzico what happened, rather than believe what came back.
     *
     * The customer's browser carries a token to the return URL and nothing
     * else. A redirect proves nothing (ADR 0024), so this retrieves the form
     * result server to server and reports what İyzico says.
     */
    public function verifyWebhook(WebhookRequest $request): ?GatewayEvent
    {
        parse_str($request->body, $fields);

        $token = (string) ($fields['token'] ?? '');

        if ($token === '') {
            return null;
        }

        $response = $this->post('/payment/iyzipos/checkoutform/auth/ecom/detail', [
            'locale' => 'tr',
            'token' => $token,
        ]);

        if ($response === null) {
            return null;
        }

        $succeeded = (string) ($response['status'] ?? '') === 'success'
            && (string) ($response['paymentStatus'] ?? '') === 'SUCCESS';

        return new GatewayEvent(
            // The token, so a browser that reloads the return URL produces the
            // same event id and is deduplicated rather than counted twice.
            id: 'iyzico:'.$token,
            type: $succeeded ? GatewayEventType::PaymentCompleted : GatewayEventType::PaymentFailed,
            paymentReference: (string) ($response['paymentId'] ?? $token),
            failureReason: $succeeded ? null : $this->reason($response),
            payload: $response,
        );
    }

    /**
     * Sign the exact bytes that are sent.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function post(string $path, array $body): ?array
    {
        $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $random = bin2hex(random_bytes(8));

        $signature = hash_hmac(
            'sha256',
            $random.$path.$payload,
            $this->secretKey,
        );

        $authorization = 'IYZWSv2 '.base64_encode(
            'apiKey:'.$this->apiKey
            .'&randomKey:'.$random
            .'&signature:'.$signature
        );

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders([
                    'Authorization' => $authorization,
                    'x-iyzi-rnd' => $random,
                    'Content-Type' => 'application/json',
                ])
                // The string, not the array: re-encoding here would sign one
                // set of bytes and send another.
                ->withBody($payload, 'application/json')
                ->post($this->apiBase.$path);
        } catch (Throwable) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reason(array $body): string
    {
        $message = $body['errorMessage'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'İyzico refused the payment.';
    }

    private function firstName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        return (string) ($parts[0] ?? '-');
    }

    private function lastName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        return count($parts) > 1 ? (string) end($parts) : '-';
    }
}
