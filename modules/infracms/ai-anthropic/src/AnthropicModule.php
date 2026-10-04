<?php

declare(strict_types=1);

namespace InfraCMS\AiAnthropic;

use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;
use App\Domain\Secrets\Contracts\SecretStore;
use App\Domain\Secrets\SecretReference;

/**
 * Claude, as a module (ADR 0050).
 *
 * The model and the address are configuration; the API key is not. Everything
 * in the manifest is a setting an operator would read out over the telephone,
 * and the key lives in the vault under `ai/api-key/anthropic`, scoped to the
 * organization that owns it — so a reseller's key is not in the provider's
 * module settings. `monitoring-prometheus` drew that line first and there is
 * no reason to draw a second one.
 *
 * The key is read **at the moment of the call**, so rotating it takes effect
 * without restarting a queue.
 *
 * **This adapter has never spoken to Anthropic.** Its request shape, its
 * retries and its error handling are tested against faked HTTP, which proves
 * the code and not the integration — the standing caveat that covers every
 * adapter in this repository.
 */
final class AnthropicModule extends BaseModule
{
    private string $model = 'claude-sonnet-5';

    private string $baseUrl = 'https://api.anthropic.com';

    private int $timeout = 30;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField('model', 'Model', ConfigFieldType::Text, default: 'claude-sonnet-5'),
            new ConfigField(
                'base_url',
                'API address',
                ConfigFieldType::Text,
                default: 'https://api.anthropic.com',
            ),
            new ConfigField('timeout', 'Timeout (seconds)', ConfigFieldType::Text, default: '30'),
        ];
    }

    public function boot(ModuleContext $context): void
    {
        $model = (string) $context->config('model', '');
        $this->model = $model === '' ? 'claude-sonnet-5' : $model;

        $base = rtrim((string) $context->config('base_url', ''), '/');
        $this->baseUrl = $base === '' ? 'https://api.anthropic.com' : $base;

        // A draft is written while somebody waits. Thirty seconds is already
        // longer than anybody stares at a button.
        $this->timeout = max(1, min(120, (int) $context->config('timeout', 30)));
    }

    /**
     * @return list<AiProvider>
     */
    public function aiProviders(): array
    {
        return [new AnthropicProvider(
            model: $this->model,
            baseUrl: $this->baseUrl,
            timeout: $this->timeout,
            // A closure rather than a value: read when the request is made,
            // so a rotated key takes effect immediately.
            apiKey: static fn (): ?string => app(SecretStore::class)->get(
                new SecretReference('ai', 'api-key', 'anthropic'),
            ),
        )];
    }
}
