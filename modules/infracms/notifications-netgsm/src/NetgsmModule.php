<?php

declare(strict_types=1);

namespace InfraCMS\NotificationsNetgsm;

use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Notifications\Contracts\DeliversNotifications;

/**
 * Netgsm, as a module.
 *
 * The sender header is required and has no default, because Netgsm rejects an
 * unregistered one outright. A module that guessed would fail every message and
 * the failure would look like bad credentials.
 */
final class NetgsmModule extends BaseModule
{
    private string $username = '';

    private string $password = '';

    private string $header = '';

    private string $countryCode = '90';

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('username', 'Netgsm number', ConfigFieldType::Text, required: true),
            new ConfigField('password', 'API password', ConfigFieldType::Secret, required: true),
            new ConfigField('header', 'Sender header', ConfigFieldType::Text, required: true),
            new ConfigField('country_code', 'Default country code', ConfigFieldType::Text, default: '90'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->username = (string) $context->config('username', '');
        $this->password = (string) $context->config('password', '');
        $this->header = (string) $context->config('header', '');
        $this->countryCode = (string) $context->config('country_code', '90');
    }

    /**
     * @return list<DeliversNotifications>
     */
    public function channels(): array
    {
        if ($this->username === '' || $this->password === '' || $this->header === '') {
            return [];
        }

        return [new NetgsmChannel(
            username: $this->username,
            password: $this->password,
            header: $this->header,
            defaultCountryCode: $this->countryCode,
        )];
    }
}
