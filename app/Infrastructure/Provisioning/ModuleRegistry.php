<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning;

use App\Domain\Provisioning\Contracts\ProvisioningModule;

/**
 * The provisioning modules this installation can actually use.
 *
 * The same rule as the gateway registry: a module missing its
 * configuration is not registered at all, rather than offered on a product
 * form and then failing at the moment a customer is waiting. The set an
 * operator sees is the set that works.
 */
final class ModuleRegistry
{
    /**
     * @var array<string, ProvisioningModule>
     */
    private array $modules = [];

    public function register(ProvisioningModule $module): void
    {
        $this->modules[$module->key()] = $module;
    }

    public function find(string $key): ?ProvisioningModule
    {
        return $this->modules[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function all(): array
    {
        return array_values($this->modules);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->modules);
    }
}
