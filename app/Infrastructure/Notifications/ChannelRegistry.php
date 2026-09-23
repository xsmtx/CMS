<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Notifications\NotificationChannel;

/**
 * The channels this installation can actually use.
 *
 * The fifth registry in this platform, following the same rule as the other
 * four: one that is not configured is not registered, so nothing tries to
 * send through a webhook endpoint nobody has set.
 */
final class ChannelRegistry
{
    /**
     * @var array<string, DeliversNotifications>
     */
    private array $channels = [];

    public function register(DeliversNotifications $channel): void
    {
        $this->channels[$channel->channel()->value] = $channel;
    }

    public function find(NotificationChannel $channel): ?DeliversNotifications
    {
        return $this->channels[$channel->value] ?? null;
    }

    public function has(NotificationChannel $channel): bool
    {
        return isset($this->channels[$channel->value]);
    }

    /**
     * @return list<DeliversNotifications>
     */
    public function all(): array
    {
        return array_values($this->channels);
    }
}
