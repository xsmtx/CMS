<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Channels;

use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Notifications\Contracts\DeliveryOutcome;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationRecipient;
use App\Domain\Notifications\RenderedMessage;
use App\Infrastructure\Notifications\Mail\TemplatedMessage;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email.
 *
 * The failure is returned rather than thrown, which is the rule for every
 * channel: a customer's payment must not roll back because a receipt could
 * not be sent. A missing receipt is a support ticket; a rolled-back payment
 * is an incident.
 */
final readonly class MailChannel implements DeliversNotifications
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::Mail;
    }

    public function deliver(NotificationRecipient $recipient, RenderedMessage $message): DeliveryOutcome
    {
        if ($recipient->email === null || $recipient->email === '') {
            return DeliveryOutcome::failed(__('notifications.errors.no_address'));
        }

        try {
            Mail::to($recipient->email, $recipient->name)
                ->send(new TemplatedMessage($message));
        } catch (Throwable $exception) {
            return DeliveryOutcome::failed($exception->getMessage());
        }

        return DeliveryOutcome::delivered();
    }
}
