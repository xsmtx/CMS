<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Api\DeliveryState;
use App\Infrastructure\Api\Models\WebhookDelivery;
use App\Infrastructure\Api\Models\WebhookEndpoint;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts one delivery and writes down what happened.
 *
 * Signed the way this platform verifies incoming webhooks: HMAC-SHA256 over
 * the **exact bytes** plus a timestamp, so a receiver can do what we do —
 * check the signature before parsing, and reject a replay by the age of the
 * timestamp. Signing a re-encoded body would produce a signature the
 * receiver cannot reproduce, which is the classic way this goes wrong.
 *
 * Retries are bounded and exponential, and an endpoint that fails enough
 * times in a row is **disabled rather than retried forever**. A dead URL
 * that keeps being posted to is a slow denial of service against this
 * platform's own queue, and the operator whose endpoint it is finds out
 * faster from a disabled switch than from silence.
 *
 * A 2xx is success. Everything else is a failure, including a 3xx: a
 * webhook receiver that redirects is misconfigured, and following it would
 * post a signed customer payload to wherever the redirect points.
 */
final readonly class DeliverWebhookNow
{
    public function __construct(
        private OrganizationContext $organizations,
        private SecretRedactor $redactor,
    ) {}

    public function handle(WebhookDelivery $delivery): WebhookDelivery
    {
        $endpoint = $this->organizations->withoutBoundary(
            static fn (): ?WebhookEndpoint => WebhookEndpoint::query()->find($delivery->endpoint_id),
        );

        if (! $endpoint instanceof WebhookEndpoint) {
            return $this->fail($delivery, null, (string) __('api.errors.endpoint_gone'), final: true);
        }

        $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $started = microtime(true);

        try {
            $response = Http::timeout($this->timeout())
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'InfraCMS-Webhook/1',
                    'X-InfraCMS-Event' => $delivery->event->value,
                    // Stable across attempts, so a receiver deduplicates on
                    // it rather than on the delivery.
                    'X-InfraCMS-Event-Id' => $delivery->event_id,
                    'X-InfraCMS-Timestamp' => $timestamp,
                    'X-InfraCMS-Signature' => hash_hmac(
                        'sha256',
                        $timestamp.'.'.$body,
                        $endpoint->secret,
                    ),
                ])
                // Never followed: a redirect would post a signed customer
                // payload to somewhere the operator did not configure.
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $exception) {
            return $this->fail(
                $delivery,
                null,
                $this->redactor->redactString($exception->getMessage()),
                durationMs: (int) round((microtime(true) - $started) * 1000),
            );
        }

        $duration = (int) round((microtime(true) - $started) * 1000);

        if (! $response->successful()) {
            return $this->fail(
                $delivery,
                $response->status(),
                mb_substr((string) $response->body(), 0, 1000),
                durationMs: $duration,
            );
        }

        $delivery->forceFill([
            'status' => DeliveryState::Delivered->value,
            'attempt' => $delivery->attempt + 1,
            'response_status' => $response->status(),
            'response_body' => mb_substr((string) $response->body(), 0, 1000),
            'error' => null,
            'duration_ms' => $duration,
            'next_attempt_at' => null,
            'delivered_at' => CarbonImmutable::now(),
        ])->save();

        $endpoint->forceFill([
            'last_delivered_at' => CarbonImmutable::now(),
            'consecutive_failures' => 0,
        ])->save();

        return $delivery;
    }

    private function fail(
        WebhookDelivery $delivery,
        ?int $status,
        string $error,
        bool $final = false,
        ?int $durationMs = null,
    ): WebhookDelivery {
        $attempt = $delivery->attempt + 1;
        $exhausted = $final || $attempt >= $this->maxAttempts();

        $delivery->forceFill([
            'status' => $exhausted ? DeliveryState::Failed->value : DeliveryState::Retrying->value,
            'attempt' => $attempt,
            'response_status' => $status,
            'error' => $error,
            'duration_ms' => $durationMs,
            'next_attempt_at' => $exhausted ? null : $this->backoffFrom($attempt),
        ])->save();

        $this->recordEndpointFailure($delivery, $exhausted);

        return $delivery;
    }

    private function recordEndpointFailure(WebhookDelivery $delivery, bool $exhausted): void
    {
        if (! $exhausted) {
            return;
        }

        $endpoint = $this->organizations->withoutBoundary(
            static fn (): ?WebhookEndpoint => WebhookEndpoint::query()->find($delivery->endpoint_id),
        );

        if (! $endpoint instanceof WebhookEndpoint) {
            return;
        }

        $failures = $endpoint->consecutive_failures + 1;
        $limit = (int) config('platform.api.webhooks.disable_after_failures', 20);

        $endpoint->forceFill([
            'consecutive_failures' => $failures,
            // Disabled, not deleted: the operator's configuration survives
            // so they can fix the URL and switch it back on.
            'disabled_at' => $failures >= $limit ? CarbonImmutable::now() : $endpoint->disabled_at,
        ])->save();
    }

    private function backoffFrom(int $attempt): CarbonImmutable
    {
        $base = (int) config('platform.api.webhooks.retry_base_minutes', 1);
        $cap = (int) config('platform.api.webhooks.retry_cap_minutes', 360);

        return CarbonImmutable::now()->addMinutes(min($cap, $base * (2 ** max(0, $attempt - 1))));
    }

    private function maxAttempts(): int
    {
        return (int) config('platform.api.webhooks.max_attempts', 6);
    }

    private function timeout(): int
    {
        return (int) config('platform.api.webhooks.timeout', 10);
    }
}
