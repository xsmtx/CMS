<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Channels;

use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Notifications\Contracts\DeliveryOutcome;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationRecipient;
use App\Domain\Notifications\RenderedMessage;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * An operator's endpoint, for a system that wants to know.
 *
 * Signed the same way this platform verifies incoming webhooks
 * ([ADR 0024](../../../../docs/adr/0024-the-ledger-is-the-truth.md)):
 * HMAC-SHA256 over the exact bytes, with a timestamp, so the receiver can
 * do what we do — check before parsing, and reject a replay.
 *
 * It carries the event and its data, never the rendered sentence. A machine
 * reading this wants `invoice.number`, not "Your invoice INV-000004 is
 * ready".
 */
final readonly class WebhookChannel implements DeliversNotifications
{
    public function __construct(
        private string $endpoint,
        #[SensitiveParameter]
        private string $secret,
        private int $timeout = 10,
    ) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Webhook;
    }

    public function deliver(NotificationRecipient $recipient, RenderedMessage $message): DeliveryOutcome
    {
        $payload = json_encode([
            'event' => $message->event->value,
            'occurred_at' => now()->toIso8601String(),
            'data' => $message->data,
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) now()->getTimestamp();

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-InfraCMS-Timestamp' => $timestamp,
                    // Over the exact bytes, with the timestamp, so a
                    // receiver can reject a replay the way we do.
                    'X-InfraCMS-Signature' => hash_hmac(
                        'sha256',
                        $timestamp.'.'.$payload,
                        $this->secret,
                    ),
                ])
                ->withBody($payload, 'application/json')
                ->post($this->endpoint);
        } catch (Throwable $exception) {
            return DeliveryOutcome::failed($exception->getMessage());
        }

        return $response->successful()
            ? DeliveryOutcome::delivered((string) $response->status())
            : DeliveryOutcome::failed(__('notifications.errors.endpoint_refused', [
                'status' => $response->status(),
            ]));
    }
}
