<?php

declare(strict_types=1);

namespace App\Application\Ai;

use App\Domain\Ai\Contracts\AiProvider;

/**
 * Which providers this installation has, by key.
 *
 * Keyed on the provider's own `key()` rather than on a class name, because a
 * seller chooses one on a settings screen and the stored value has to survive
 * a module being reinstalled. `ChannelRegistry` keys on the class name for the
 * opposite reason — there, several implementations serve one channel and the
 * key exists to keep them apart; here, the key *is* what somebody picked.
 *
 * A module registers through `ModuleContext` at boot, exactly as it registers
 * a gateway. Nothing is registered by core: this is empty on an installation
 * that has enabled no AI module, which is the shipped state.
 */
final class AiProviders
{
    /** @var array<string, AiProvider> */
    private array $providers = [];

    public function register(AiProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): ?AiProvider
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * @return list<AiProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function any(): bool
    {
        return $this->providers !== [];
    }
}
