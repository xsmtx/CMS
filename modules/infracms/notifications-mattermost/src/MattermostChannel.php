<?php

declare(strict_types=1);

namespace InfraCMS\NotificationsMattermost;

use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Notifications\Contracts\DeliveryOutcome;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationRecipient;
use App\Domain\Notifications\RenderedMessage;
use App\Support\Http\SafeUrl;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mattermost, over an incoming webhook.
 *
 * **It never throws.** A chat room that is down must not take down the operation
 * that triggered the message — an invoice is still issued when Slack is having
 * an afternoon — so every failure becomes a `DeliveryOutcome` and a row.
 *
 * **The URL is checked immediately before the request**, not when it was saved.
 * DNS can change in between and that is the whole technique; and an operator who
 * pasted an internal address gets a refusal rather than a request from this
 * server to somewhere on its own network.
 *
 * **The message is posted as text, not as the rendered email.** A notification's
 * body is written for a person reading a message, and a chat room wants the
 * subject, a line and a link — not a wall of HTML.
 *
 * It has never posted to a real Mattermost workspace. Written against the
 * published documentation and tested against faked HTTP.
 */
final readonly class MattermostChannel implements DeliversNotifications
{
    public function __construct(
        private string $webhookUrl,
        private string $username = 'InfraCMS',
        private int $timeout = 10,
    ) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Chat;
    }

    public function deliver(NotificationRecipient $recipient, RenderedMessage $message): DeliveryOutcome
    {
        if (! SafeUrl::allows($this->webhookUrl)) {
            // Never echoes the URL back: a refusal that quotes a remote address
            // is a refusal that can be used to probe what this server reaches.
            return new DeliveryOutcome(delivered: false, error: 'That webhook address is not allowed.');
        }

        try {
            $response = Http::timeout($this->timeout)
                ->retry(2, 250, throw: false)
                ->post($this->webhookUrl, [
                    'text' => $this->text($message),
                    'username' => $this->username,
                ]);
        } catch (Throwable $exception) {
            return new DeliveryOutcome(delivered: false, error: $exception->getMessage());
        }

        return $response->successful()
            ? new DeliveryOutcome(delivered: true)
            : new DeliveryOutcome(delivered: false, error: 'Mattermost refused the message.');
    }

    /**
     * Subject, body and a link, in that order.
     */
    private function text(RenderedMessage $message): string
    {
        $lines = [$message->subject, $message->body];

        if ($message->actionUrl !== null && $message->actionUrl !== '') {
            $lines[] = ($message->actionLabel ?? 'Open').': '.$message->actionUrl;
        }

        return implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }
}
