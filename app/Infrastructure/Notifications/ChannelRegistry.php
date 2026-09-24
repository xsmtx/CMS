<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Notifications\NotificationChannel;

/**
 * The channels this installation can actually use, and who delivers them.
 *
 * The fifth registry in this platform, following the same rule as the other
 * four: one that is not configured is not registered, so nothing tries to send
 * through a webhook endpoint nobody has set.
 *
 * **Keyed by channel *and* implementation**, which it was not at first. Keying
 * on the channel alone meant one provider per channel: Discord, Slack and
 * Mattermost are all chat, and each registration overwrote the last — and would
 * have overwritten core's own webhook channel had they registered as that.
 *
 * The implementation's class name is the second half of the key rather than
 * something the interface asks for, deliberately. Asking would mean adding a
 * method to `DeliversNotifications`, which is an SDK contract a module
 * implements — a major version bump, and every module refusing until its author
 * looked (ADR 0039), for a value that can simply be derived.
 *
 * **Several providers on one channel all deliver.** For chat that is what an
 * operator wants: a message goes to the Slack room and the Discord room. For
 * SMS an installation registers one, and registering two would be asking to pay
 * twice — which is visible, because each delivery writes its own row.
 */
final class ChannelRegistry
{
    /**
     * @var array<string, DeliversNotifications>
     */
    private array $channels = [];

    public function register(DeliversNotifications $channel): void
    {
        $this->channels[$channel->channel()->value.'|'.$channel::class] = $channel;
    }

    /**
     * One deliverer for this channel, for a caller that wants any of them.
     */
    public function find(NotificationChannel $channel): ?DeliversNotifications
    {
        return $this->allFor($channel)[0] ?? null;
    }

    /**
     * Everything registered for this channel, in registration order.
     *
     * @return list<DeliversNotifications>
     */
    public function allFor(NotificationChannel $channel): array
    {
        return array_values(array_filter(
            $this->channels,
            static fn (DeliversNotifications $deliverer): bool => $deliverer->channel() === $channel,
        ));
    }

    public function has(NotificationChannel $channel): bool
    {
        return $this->allFor($channel) !== [];
    }

    /**
     * @return list<DeliversNotifications>
     */
    public function all(): array
    {
        return array_values($this->channels);
    }
}
