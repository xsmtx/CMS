<?php

declare(strict_types=1);

namespace InfraCMS\NotificationsDiscord;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Notifications\Contracts\DeliversNotifications;

/**
 * Discord notifications, as a module.
 *
 * A chat room is the **operator's** endpoint, not a customer's address, which is
 * why there is nothing here about who to tell: everything this installation
 * sends on the chat channel lands in the same room. That also means this module
 * and the other chat modules coexist rather than compete — the registry holds a
 * provider per channel *and* implementation, so Slack and Discord both deliver.
 *
 * The webhook URL is the whole credential. Anyone holding it can post to that
 * room, which is why it is a secret field and not a URL one.
 */
final class DiscordModule extends BaseModule
{
    private string $webhook = '';

    private string $username = 'InfraCMS';

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('webhook_url', 'Webhook URL', ConfigFieldType::Secret, required: true),
            new ConfigField('username', 'Post as', ConfigFieldType::Text, default: 'InfraCMS'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->webhook = (string) $context->config('webhook_url', '');
        $this->username = (string) $context->config('username', 'InfraCMS');
    }

    /**
     * @return list<DeliversNotifications>
     */
    public function channels(): array
    {
        // No webhook means no registration: a channel that is not configured is
        // not offered, so nothing tries to post to a room nobody set.
        if ($this->webhook === '') {
            return [];
        }

        return [new DiscordChannel(webhookUrl: $this->webhook, username: $this->username)];
    }
}
