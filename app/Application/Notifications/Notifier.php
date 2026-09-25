<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationRecipient;
use App\Domain\Notifications\RenderedMessage;
use App\Domain\Shared\Money;
use App\Infrastructure\Notifications\ChannelRegistry;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Support\Branding\CurrentBrand;
use App\Support\Correlation\CorrelationContext;
use App\Support\Logging\SecretRedactor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place a message leaves this platform.
 *
 * A domain event says what happened. This decides who should be told, in
 * what language, through which channel, and whether they asked not to be —
 * four questions that do not belong to the code that took a payment.
 *
 * Three rules hold regardless of channel:
 *
 * - **Every send writes a row.** Sent, failed, or suppressed because
 *   somebody opted out. "Did the customer get the suspension warning" is
 *   the first question of every dispute, and an absence answers nothing.
 * - **One channel failing does not stop the others.** A mail server being
 *   down must not cost the customer their in-app copy.
 * - **Nothing thrown here escapes.** A receipt that could not be sent is a
 *   support ticket; a payment rolled back because a receipt could not be
 *   sent is an incident.
 */
final readonly class Notifier
{
    public function __construct(
        private ChannelRegistry $channels,
        private RenderTemplate $renderer,
        private CorrelationContext $correlation,
        private SecretRedactor $redactor,
        private CurrentBrand $brands,
    ) {}

    /**
     * @param  list<NotificationRecipient>  $recipients
     * @param  array<string, string|int|float|CarbonInterface|Money|null>  $data
     * @param  list<NotificationChannel>|null  $channels  Null means the configured default.
     */
    public function send(
        NotificationEvent $event,
        array $recipients,
        array $data = [],
        ?string $actionUrl = null,
        ?array $channels = null,
        ?string $organizationId = null,
    ): void {
        $channels ??= $this->defaultChannels();

        // Resolved once for the whole send rather than per recipient: it
        // is the sender's brand, and the sender does not change between two
        // contacts on one account.
        $brand = $organizationId === null
            ? null
            : $this->brands->forDocument($organizationId);

        foreach ($recipients as $recipient) {
            $message = $this->renderer->handle($event, $recipient->locale, $data, $actionUrl);

            if ($brand !== null) {
                $message = $message->under($brand);
            }

            foreach ($channels as $channel) {
                $this->deliverOne($event, $channel, $recipient, $message, $organizationId);
            }
        }
    }

    private function deliverOne(
        NotificationEvent $event,
        NotificationChannel $channel,
        NotificationRecipient $recipient,
        RenderedMessage $message,
        ?string $organizationId,
    ): void {
        // Recorded, not obeyed silently: "they asked us not to" is an
        // answer, and an absent row is not.
        if (! $recipient->acceptsCategory) {
            $this->record(
                $event,
                $channel,
                $recipient,
                $message,
                DeliveryStatus::Suppressed,
                $organizationId,
                error: (string) __('notifications.errors.opted_out'),
            );

            return;
        }

        if (! $recipient->isAddressable($channel)) {
            $this->record(
                $event,
                $channel,
                $recipient,
                $message,
                DeliveryStatus::Suppressed,
                $organizationId,
                error: (string) __('notifications.errors.no_address'),
            );

            return;
        }

        $drivers = $this->channels->allFor($channel);

        if ($drivers === []) {
            // Not configured on this installation. Not an error: it is the
            // operator's choice, and the row says the message existed.
            return;
        }

        // Every provider registered for this channel, each writing its own
        // delivery row. Chat is the case that needs it — a message goes to the
        // Slack room *and* the Discord room — and one failing must not stop the
        // others, exactly as one channel failing never stops the rest.
        foreach ($drivers as $driver) {
            $this->deliverThrough($driver, $event, $channel, $recipient, $message, $organizationId);
        }
    }

    private function deliverThrough(
        DeliversNotifications $driver,
        NotificationEvent $event,
        NotificationChannel $channel,
        NotificationRecipient $recipient,
        RenderedMessage $message,
        ?string $organizationId,
    ): void {
        try {
            $outcome = $driver->deliver($recipient, $message);
        } catch (Throwable $exception) {
            // A channel that throws rather than returning is a bug in the
            // channel. It must still not take down the operation that
            // triggered the message.
            $this->record(
                $event,
                $channel,
                $recipient,
                $message,
                DeliveryStatus::Failed,
                $organizationId,
                error: $exception->getMessage(),
            );

            return;
        }

        $this->record(
            $event,
            $channel,
            $recipient,
            $message,
            $outcome->delivered ? DeliveryStatus::Sent : DeliveryStatus::Failed,
            $organizationId,
            reference: $outcome->reference,
            error: $outcome->error,
        );
    }

    private function record(
        NotificationEvent $event,
        NotificationChannel $channel,
        NotificationRecipient $recipient,
        RenderedMessage $message,
        DeliveryStatus $status,
        ?string $organizationId,
        ?string $reference = null,
        ?string $error = null,
    ): void {
        NotificationDelivery::query()->create([
            'organization_id' => $organizationId,
            'event' => $event->value,
            'channel' => $channel->value,
            'status' => $status->value,
            'recipient_name' => $recipient->name,
            'recipient_address' => $recipient->email,
            'subject_type' => $recipient->subjectType,
            'subject_id' => $recipient->subjectId,
            'locale' => $message->locale,
            'rendered_subject' => Str::limit($message->subject, 190, ''),
            'reference' => $reference,
            // A provider's error text quotes back the request that caused
            // it, which for a mail server means the whole message.
            'error' => $error === null ? null : $this->redactor->redactString($error),
            'correlation_id' => $this->correlation->id(),
            'created_at' => CarbonImmutable::now(),
            'delivered_at' => $status === DeliveryStatus::Sent ? CarbonImmutable::now() : null,
        ]);
    }

    /**
     * @return list<NotificationChannel>
     */
    private function defaultChannels(): array
    {
        /** @var list<string> $configured */
        $configured = config('platform.notifications.channels', ['mail', 'database']);

        return array_values(array_filter(array_map(
            NotificationChannel::tryFrom(...),
            $configured,
        )));
    }
}
