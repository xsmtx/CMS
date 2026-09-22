<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use SensitiveParameter;

/**
 * Password reset for the client area.
 *
 * A separate notification from the staff one purely so the link lands on the
 * client reset screen. The two brokers issue different tokens, and a token
 * that opens the wrong form is a dead end for the customer.
 */
final class ContactPasswordReset extends Notification
{
    use Queueable;

    public function __construct(#[SensitiveParameter] private readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('client.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getAttribute('email'),
        ], absolute: false));

        $minutes = (int) config('auth.passwords.contacts.expire', 60);

        return (new MailMessage)
            ->subject(Lang::get('identity.mail.reset_subject'))
            ->line(Lang::get('identity.mail.reset_intro'))
            ->action(Lang::get('identity.mail.reset_action'), url($url))
            ->line(Lang::get('identity.mail.reset_expiry', ['minutes' => $minutes]))
            ->line(Lang::get('identity.mail.reset_ignore'));
    }
}
