<?php

declare(strict_types=1);

namespace InfraCMS\GatewayPayTR;

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
 * PayTR, over its iframe API.
 *
 * PayTR is a **redirect gateway and nothing else**: there is no way to charge a
 * stored card unattended, so `unattendedCharges` is false and a renewal is
 * something the customer is asked to pay rather than something this platform
 * takes. Saying otherwise here would make the renewal sweep raise invoices it
 * then silently failed to collect.
 *
 * Three things about it are worth knowing before changing anything:
 *
 * **Every message is authenticated by a hash, not by a header.** The token
 * request is signed with the merchant key over a concatenation that ends in the
 * salt, and the callback is signed the same way. The salt is never transmitted —
 * that is the whole reason it is a separate secret.
 *
 * **The amount is in kuruş and is a string.** Money here is already integer
 * minor units, so there is no conversion and nothing to round. A gateway that
 * multiplied a float by 100 is how a customer is charged 1 999,99 for something
 * priced 2 000,00.
 *
 * **The callback is the only thing that moves money.** A customer returning to
 * the success URL proves nothing (ADR 0024); `verifyWebhook` is where a payment
 * becomes real, and it verifies before it parses.
 *
 * It has never talked to PayTR. Its request shapes and its hashing are written
 * against the published documentation and tested against faked HTTP, which
 * proves the code and not the integration.
 */
final readonly class PayTRGateway implements PaymentGateway
{
    private const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';

    private const PAY_URL = 'https://www.paytr.com/odeme/guvenli/';

    public function __construct(
        private string $merchantId,
        private string $merchantKey,
        private string $merchantSalt,
        private bool $testMode = false,
        private bool $instalmentsAllowed = true,
        private int $timeoutSeconds = 20,
    ) {}

    public function key(): string
    {
        return 'paytr';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            refunds: true,
            partialRefunds: true,
            // No stored methods and no unattended charge: PayTR hands back a
            // payment page, never a token this platform may charge later.
            storedMethods: false,
            webhooks: true,
            redirects: true,
            unattendedCharges: false,
        );
    }

    public function createPayment(PaymentIntent $intent): PaymentResult
    {
        $basket = $this->basket($intent);
        $amount = (string) $intent->amount->minorUnits;

        $hashed = $this->merchantId
            .($intent->metadata['ip'] ?? '0.0.0.0')
            .$intent->reference
            .($intent->customerEmail ?? '')
            .$amount
            .$basket
            .($this->instalmentsAllowed ? '0' : '1')
            .'0'
            .strtolower($intent->amount->currency->code)
            .($this->testMode ? '1' : '0');

        $token = base64_encode(hash_hmac('sha256', $hashed.$this->merchantSalt, $this->merchantKey, true));

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->asForm()
                ->post(self::TOKEN_URL, [
                    'merchant_id' => $this->merchantId,
                    'user_ip' => $intent->metadata['ip'] ?? '0.0.0.0',
                    'merchant_oid' => $intent->reference,
                    'email' => $intent->customerEmail ?? '',
                    'payment_amount' => $amount,
                    'paytr_token' => $token,
                    'user_basket' => $basket,
                    'debug_on' => 0,
                    'no_installment' => $this->instalmentsAllowed ? 0 : 1,
                    'max_installment' => 0,
                    'user_name' => $intent->customerName ?? '',
                    'user_address' => $intent->metadata['address'] ?? '-',
                    'user_phone' => $intent->metadata['phone'] ?? '-',
                    'merchant_ok_url' => $intent->returnUrl ?? '',
                    'merchant_fail_url' => $intent->cancelUrl ?? '',
                    'timeout_limit' => 30,
                    'currency' => strtoupper($intent->amount->currency->code),
                    'test_mode' => $this->testMode ? 1 : 0,
                ]);
        } catch (Throwable $exception) {
            // A network failure is not a decline. Nothing is marked paid.
            return PaymentResult::failed($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed() || (string) ($body['status'] ?? '') !== 'success') {
            return PaymentResult::failed($this->reason($body));
        }

        return PaymentResult::pending(
            $intent->reference,
            self::PAY_URL.(string) ($body['token'] ?? ''),
            $body,
        );
    }

    /**
     * PayTR refunds by merchant order id, not by a payment id of its own.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $hash = base64_encode(hash_hmac(
            'sha256',
            $this->merchantId.$request->paymentReference.$request->amount->toDecimalString().$this->merchantSalt,
            $this->merchantKey,
            true,
        ));

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->asForm()
                ->post('https://www.paytr.com/odeme/iade', [
                    'merchant_id' => $this->merchantId,
                    'merchant_oid' => $request->paymentReference,
                    'return_amount' => $request->amount->toDecimalString(),
                    'paytr_token' => $hash,
                ]);
        } catch (Throwable $exception) {
            return RefundResult::refused($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed() || (string) ($body['status'] ?? '') !== 'success') {
            return RefundResult::refused($this->reason($body));
        }

        return RefundResult::accepted($request->paymentReference, $body);
    }

    /**
     * Verify first, parse second.
     *
     * The hash is over the merchant order id, the salt, the status and the
     * total, compared in constant time. A callback that does not verify is not
     * read at all — which is the only reason this is safe to expose.
     */
    public function verifyWebhook(WebhookRequest $request): ?GatewayEvent
    {
        parse_str($request->body, $fields);

        $order = (string) ($fields['merchant_oid'] ?? '');
        $status = (string) ($fields['status'] ?? '');
        $total = (string) ($fields['total_amount'] ?? '');
        $hash = (string) ($fields['hash'] ?? '');

        if ($order === '' || $hash === '') {
            return null;
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $order.$this->merchantSalt.$status.$total,
            $this->merchantKey,
            true,
        ));

        if (! hash_equals($expected, $hash)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = $fields;

        return new GatewayEvent(
            // PayTR sends no event id, so the order id is the deduplication
            // key — which is what makes a repeated callback idempotent.
            id: 'paytr:'.$order.':'.$status,
            type: $status === 'success' ? GatewayEventType::PaymentCompleted : GatewayEventType::PaymentFailed,
            paymentReference: $order,
            amount: $total === '' ? null : Money::ofMinor((int) $total, $this->currencyOf($fields)),
            failureReason: $status === 'success' ? null : (string) ($fields['failed_reason_msg'] ?? ''),
            payload: $payload,
        );
    }

    /**
     * The one line PayTR insists on, as its own JSON array.
     *
     * The invoice's own lines are not sent: a basket is shown on a page the
     * customer is about to read, and copying every line of a hosting invoice
     * onto it tells them nothing they did not already agree to.
     */
    private function basket(PaymentIntent $intent): string
    {
        return base64_encode((string) json_encode(
            [[$intent->description, $intent->amount->toDecimalString(), 1]],
            JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function currencyOf(array $fields): string
    {
        $currency = strtoupper((string) ($fields['currency'] ?? 'TRY'));

        // PayTR writes Turkish lira as TL in some responses; ISO 4217 does not.
        return $currency === 'TL' ? 'TRY' : $currency;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reason(array $body): string
    {
        $reason = $body['reason'] ?? $body['err_msg'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : 'PayTR refused the request.';
    }
}
