<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationRecipient;
use App\Domain\Notifications\RenderedMessage;

/**
 * One way of getting a message to somebody.
 *
 * The fifth provider contract in this platform, and it follows the same
 * four rules as the others:
 *
 * 1. **A channel never touches the database.** It takes a rendered message
 *    and a recipient and moves bytes. Recording what happened is the
 *    caller's job, done once, in one place.
 * 2. **Every send is bounded** — a timeout, and a failure that is returned
 *    rather than thrown, because one channel failing must not stop the
 *    others.
 * 3. **It reports, it does not decide.** Whether this recipient should be
 *    told, in what language, and whether they opted out, was settled before
 *    the channel was called.
 * 4. **It is registered only when configured.** A webhook channel with no
 *    endpoint is not offered.
 */
interface DeliversNotifications
{
    public function channel(): NotificationChannel;

    public function deliver(NotificationRecipient $recipient, RenderedMessage $message): DeliveryOutcome;
}
