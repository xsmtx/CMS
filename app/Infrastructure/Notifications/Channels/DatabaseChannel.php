<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Channels;

use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Notifications\Contracts\DeliveryOutcome;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationRecipient;
use App\Domain\Notifications\RenderedMessage;
use App\Infrastructure\Notifications\Models\InAppNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * In-app: a row the recipient sees next time they sign in.
 *
 * The one channel that cannot bounce, which makes it the right home for
 * anything a customer would otherwise only learn by noticing.
 */
final readonly class DatabaseChannel implements DeliversNotifications
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::Database;
    }

    public function deliver(NotificationRecipient $recipient, RenderedMessage $message): DeliveryOutcome
    {
        if ($recipient->subjectType === null || $recipient->subjectId === null) {
            // Nobody to address it to. A webhook endpoint has no inbox.
            return DeliveryOutcome::failed(__('notifications.errors.no_subject'));
        }

        try {
            $record = InAppNotification::query()->create([
                // Copied from whoever the message is for, so the row sits
                // inside the same boundary as the person reading it.
                'organization_id' => $this->organizationOf($recipient),
                'notifiable_type' => $recipient->subjectType,
                'notifiable_id' => $recipient->subjectId,
                'event' => $message->event->value,
                'title' => $message->subject,
                'body' => $message->body,
                'action_url' => $message->actionUrl,
                'created_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $exception) {
            return DeliveryOutcome::failed($exception->getMessage());
        }

        return DeliveryOutcome::delivered($record->id);
    }

    /**
     * The recipient's own organization, when they have one.
     */
    private function organizationOf(NotificationRecipient $recipient): ?string
    {
        $class = $recipient->subjectType;

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model|null $subject */
        $subject = $class::query()
            ->withoutGlobalScope('organization')
            ->find($recipient->subjectId);

        $organizationId = $subject?->getAttribute('organization_id');

        return is_string($organizationId) ? $organizationId : null;
    }
}
