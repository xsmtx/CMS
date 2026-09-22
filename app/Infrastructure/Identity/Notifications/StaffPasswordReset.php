<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use SensitiveParameter;

/**
 * Password reset for the admin area.
 */
final class StaffPasswordReset extends Notification
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
        $url = url(route('admin.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getAttribute('email'),
        ], absolute: false));

        $minutes = (int) config('auth.passwords.staff_users.expire', 60);

        return (new MailMessage)
            ->subject(Lang::get('identity.mail.reset_subject'))
            ->line(Lang::get('identity.mail.reset_intro'))
            ->action(Lang::get('identity.mail.reset_action'), url($url))
            ->line(Lang::get('identity.mail.reset_expiry', ['minutes' => $minutes]))
            ->line(Lang::get('identity.mail.reset_ignore'));
    }
}
