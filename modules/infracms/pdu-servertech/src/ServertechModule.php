<?php

declare(strict_types=1);

namespace InfraCMS\PduServertech;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Infrastructure\Power\PowerFeed;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * A Server Technology PDU, as a module.
 *
 * **Which feed this PDU is, is configuration.** A rack normally has two, one
 * per feed, and no PDU knows which of the pair it is — an operator does, and
 * saying so is what turns "forty things are on this PDU" into "this device is
 * on A only", which is the question a power diagram exists to answer.
 *
 * The address and the user are configuration; the password is not, and lives
 * in the vault under `power/password/servertech` — read at the moment of the
 * call, so a rotation takes effect without restarting a queue.
 *
 * **One module per PDU**, which is what a rack of two means: two rows on the
 * Adapters screen, each with its own address, its own credential and its own
 * feed. An adapter that tried to be every PDU in the building would need an
 * inventory this platform does not have.
 *
 * It has never talked to a real PDU.
 */
final class ServertechModule extends BaseModule
{
    private string $baseUrl = '';

    private string $username = '';

    private PowerFeed $feed = PowerFeed::Unknown;

    private bool $verifyTls = true;

    private int $timeout = 10;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('base_url', 'PDU address', ConfigFieldType::Text, required: true),
            new ConfigField('username', 'API user', ConfigFieldType::Text, required: true),
            new ConfigField(
                'feed',
                'Which feed this PDU is',
                ConfigFieldType::Select,
                default: PowerFeed::Unknown->value,
                options: [
                    ['value' => PowerFeed::A->value, 'label' => 'A'],
                    ['value' => PowerFeed::B->value, 'label' => 'B'],
                    ['value' => PowerFeed::Unknown->value, 'label' => 'Not stated'],
                ],
            ),
            new ConfigField('verify_tls', 'Verify the certificate', ConfigFieldType::Boolean, default: true),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '10'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $this->baseUrl = rtrim((string) $context->config('base_url', ''), '/');
        $this->username = (string) $context->config('username', '');
        $this->feed = PowerFeed::tryFrom((string) $context->config('feed', '')) ?? PowerFeed::Unknown;
        $this->verifyTls = (bool) $context->config('verify_tls', true);
        $this->timeout = max(1, (int) $context->config('timeout', 10));
    }

    /**
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array
    {
        if ($this->baseUrl === '' || $this->username === '') {
            return [];
        }

        return [new ServertechProvider(
            baseUrl: $this->baseUrl,
            username: $this->username,
            password: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('power', 'password', 'servertech'),
            ),
            feed: $this->feed,
            verifyTls: $this->verifyTls,
            timeout: $this->timeout,
        )];
    }
}
